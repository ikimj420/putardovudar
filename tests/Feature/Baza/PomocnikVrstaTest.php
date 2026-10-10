<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\PretragaPrilika;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 30: vrsta koju je model samo pogodio („radionica" kao „obuka") ne sme da sakrije priliku; izrečena vrsta je tvrda.
#[Group('baza')]
class PomocnikVrstaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-12-15',
            'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu.',
        ], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $forma */
    private function pitaj(string $pitanje, array $forma): RezultatPretrage
    {
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE)]])]);

        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function pogresnaVrsta(): array
    {
        return [
            '#26 radionica' => ['gde ima radionica za mlade', ['vrsta' => 'obuka', 'kome' => 'mladi', 'kljucne_reci' => ['radionica']], 'Radionica za mlade: digitalne veštine'],
            '#27 volontiranje' => ['volontiranje', ['vrsta' => 'obuka', 'kljucne_reci' => ['volontiranje']], 'Volontiranje u omladinskom centru'],
            '#P17 grantovi' => ['grantovi za preduzetnike', ['vrsta' => 'stipendija', 'kome' => 'preduzetnici', 'kljucne_reci' => ['grantovi', 'preduzetnike']], 'Grant za mlade preduzetnike'],
        ];
    }

    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('pogresnaVrsta')]
    public function vrsta_koju_pitanje_ne_pominje_ne_krije_priliku(string $pitanje, array $forma, string $naslov): void
    {
        $this->prilika(['naslov' => 'Radionica za mlade: digitalne veštine', 'vrsta' => VrstaPrilike::Drugo, 'kratak_opis' => 'Radionica za mlade o digitalnim veštinama.', 'opis' => '']);
        $this->prilika(['naslov' => 'Volontiranje u omladinskom centru', 'vrsta' => VrstaPrilike::Drugo, 'kratak_opis' => 'Rad sa mladima.', 'opis' => '']);
        // Druga ciljna grupa: rezervna pretraga ne sme da izgubi „kome".
        $this->prilika(['naslov' => 'Radionica za penzionere', 'vrsta' => VrstaPrilike::Drugo, 'kratak_opis' => 'Radionica za penzionere.', 'opis' => '']);
        $this->prilika(['naslov' => 'Grant za mlade preduzetnike', 'vrsta' => VrstaPrilike::Konkurs, 'kratak_opis' => 'Grantovi za mlade preduzetnike.', 'opis' => '']);

        $rezultat = $this->pitaj($pitanje, $forma);

        $this->assertSame([$naslov], $rezultat->zapisi->pluck('naslov')->all());
        $this->assertFalse($rezultat->formular->vrstaJePomenuta);
    }

    // Ogledalo: izrečena vrsta ostaje tvrda, pa „stipendija u Kragujevcu" ne vraća posao i praksu iz Kragujevca.
    #[Test]
    public function vrsta_koju_pitanje_pominje_ostaje_tvrda(): void
    {
        $this->prilika(['naslov' => 'Pomoćni radnik u pekari', 'mesto' => 'Kragujevac']);
        $this->prilika(['naslov' => 'Stručna praksa za mlade', 'vrsta' => VrstaPrilike::Praksa, 'mesto' => 'Kragujevac']);

        $rezultat = $this->pitaj('stipendija u Kragujevcu', ['vrsta' => 'stipendija', 'grad' => 'Kragujevac']);

        $this->assertTrue($rezultat->formular->vrstaJePomenuta);
        $this->assertTrue($rezultat->nemaPodatak());
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function pomenutaIliNe(): array
    {
        return [
            'posao u padežu' => ['Ima li posla u Nišu?', 'posao', true],
            'posao sa greškom' => ['treba poso', 'posao', true],
            'stipendije' => ['Tražim stipendije za studente', 'stipendija', true],
            'obuke' => ['obuke za odrasle', 'obuka', true],
            'konkursa' => ['Ima li konkursa?', 'konkurs', true],
            'praksu' => ['Tražim praksu za mlade', 'praksa', true],
            'kurs nije obuka' => ['online kurs', 'obuka', false],
            'radionica nije obuka' => ['gde ima radionica za mlade', 'obuka', false],
            'grantovi nisu stipendija' => ['grantovi za preduzetnike', 'stipendija', false],
            'takmičenje nije konkurs' => ['takmicenje za srednju skolu', 'konkurs', false],
            'radim nije posao' => ['hoću da radim', 'posao', false],
            'greška u pisanju' => ['stpendija', 'stipendija', false],
            'drugo nikad nije izrečeno' => ['Ima li drugih prilika, drugo?', 'drugo', false],
        ];
    }

    #[Test]
    #[DataProvider('pomenutaIliNe')]
    public function vrsta_je_pomenuta_samo_kad_pitanje_kaze_njen_naziv(string $pitanje, string $vrsta, bool $ocekivano): void
    {
        $this->assertSame($ocekivano, $this->pitaj($pitanje, ['vrsta' => $vrsta])->formular->vrstaJePomenuta, $pitanje);
    }

    // Prazan formular je tvrd po podrazumevanju, a prazan odgovor modela ostaje prazan formular.
    #[Test]
    public function bez_vrste_u_formularu_nema_ni_pomenute_vrste(): void
    {
        $this->assertTrue((new Formular)->vrstaJePomenuta);
        $this->assertTrue($this->pitaj('Ima li nečega?', [])->nemaPodatak());
    }

    // Široka pretraga je samo rezervni put: kad sa vrstom ima rezultata, vraća se samo to.
    #[Test]
    public function kad_sa_vrstom_ima_rezultata_ne_trazi_se_bez_nje(): void
    {
        $this->prilika(['naslov' => 'Kurs radionica engleskog', 'vrsta' => VrstaPrilike::Obuka, 'kratak_opis' => 'Kurs.', 'opis' => '']);
        $this->prilika(['naslov' => 'Radionica za mlade', 'vrsta' => VrstaPrilike::Drugo, 'kratak_opis' => 'Radionica.', 'opis' => '']);

        $rezultat = $this->pitaj('gde ima radionica', ['vrsta' => 'obuka', 'kljucne_reci' => ['radionica']]);

        $this->assertSame(['Kurs radionica engleskog'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Bez vrste mora da ostane bar jedan uslov, inače bi „kurs" (samo vrsta) vratio sve prilike.
    #[Test]
    public function bez_vrste_se_ne_traze_sve_prilike_kad_nema_drugog_uslova(): void
    {
        $this->prilika(['naslov' => 'Volontiranje u omladinskom centru', 'vrsta' => VrstaPrilike::Drugo]);

        $rezultat = $this->pitaj('kurs', ['vrsta' => 'obuka']);

        $this->assertFalse($rezultat->formular->vrstaJePomenuta);
        $this->assertTrue($rezultat->nemaPodatak());
    }

    // Rezervna pretraga poštuje ostale uslove i javnost: grad, kome i samo objavljeno.
    #[Test]
    public function rezervna_pretraga_poštuje_grad_i_javnost(): void
    {
        $this->prilika(['naslov' => 'Volontiranje u Beogradu', 'vrsta' => VrstaPrilike::Drugo, 'mesto' => 'Beograd']);
        $this->prilika(['naslov' => 'Volontiranje u Nišu', 'vrsta' => VrstaPrilike::Drugo, 'mesto' => 'Niš']);
        $this->prilika(['naslov' => 'Volontiranje nacrt', 'vrsta' => VrstaPrilike::Drugo, 'mesto' => 'Niš', 'status' => StatusPrilike::Nacrt]);

        $rezultat = $this->pitaj('volontiranje u Nišu', ['vrsta' => 'obuka', 'grad' => 'Niš', 'kljucne_reci' => ['volontiranje']]);

        $this->assertSame(['Volontiranje u Nišu'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Formular koji je sklopljen u kodu (bez oznake) ostaje tvrd: oznaka „pomenuta" je podrazumevano tačna.
    #[Test]
    public function formular_bez_oznake_je_tvrd(): void
    {
        $this->prilika(['naslov' => 'Volontiranje u omladinskom centru', 'vrsta' => VrstaPrilike::Drugo]);
        $pretraga = app(PretragaPrilika::class);

        $this->assertCount(0, $pretraga->pronadji(new Formular(VrstaPrilike::Obuka, null, null, ['volontiranje'])));
        $this->assertCount(1, $pretraga->pronadji(new Formular(VrstaPrilike::Obuka, null, null, ['volontiranje'], null, false)));
    }
}

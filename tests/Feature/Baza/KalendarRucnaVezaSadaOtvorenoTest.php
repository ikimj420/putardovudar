<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Filament\Resources\Kalendar\Pages\CreateKalendarStavka;
use App\Filament\Resources\Kalendar\Pages\EditKalendarStavka;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 89 (Ivan, 30.10.2026.): uz stavku kalendara može da se izabere javna prilika („Sada otvoreno kao"); kalendar tada piše „Sada otvoreno" i kad link nije isti.
// Stara pravila (isti link) i pravilo „ništa nije najava" važe kao i do sada, a oznaka nestaje sama kad prilika prestane da bude javna.
#[Group('baza')]
class KalendarRucnaVezaSadaOtvorenoTest extends AdminBazaTestCase
{
    use VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function stavka(array $dopuna = []): KalendarStavka
    {
        return KalendarStavka::factory()->objavljena()->create([...['naziv' => 'Studentske stipendije 2025/26', 'otvoreno_do' => '2025-11-03', 'link_izvora' => 'https://ministarstvo.example/konkurs-2025.pdf'], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...['naslov' => 'Studentske stipendije 2026/27', 'rok' => '2026-11-15', 'link_izvora' => 'https://ministarstvo.example/vest-2026'], ...$dopuna]);
    }

    // Veza kako stoji u bazi, ne kako je upamćena u objektu.
    private function vezaStavke(KalendarStavka $stavka): mixed
    {
        return KalendarStavka::query()->whereKey($stavka->getKey())->value('prilika_id');
    }

    private function strana(): string
    {
        return (string) $this->get(route('kalendar.index'))->assertOk()->getContent();
    }

    #[Test]
    public function izabrana_javna_prilika_sa_drugim_linkom_znaci_sada_otvoreno_i_kartica_vodi_na_nju(): void
    {
        $prilika = $this->prilika();
        $stavka = $this->stavka(['prilika_id' => $prilika->getKey()]);

        $html = $this->strana();

        $this->assertStringContainsString('Sada otvoreno: Studentske stipendije 2026/27', $this->vidljivTekst($html));
        $this->assertStringContainsString('<h3><a class="kartica-veza" href="'.route('prilike.show', $prilika->slug).'">Studentske stipendije 2025/26</a></h3>', $html);

        // Ogledalo: ista stavka bez izabrane prilike (linkovi su različiti) nema oznaku ni vezu.
        $stavka->forceFill(['prilika_id' => null])->save();

        $html = $this->strana();

        $this->assertStringNotContainsString('Sada otvoreno', $this->vidljivTekst($html));
        $this->assertStringNotContainsString(route('prilike.show', $prilika->slug), $html);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function prilikeKojeViseNisuJavne(): array
    {
        return [
            'postala nacrt' => [['status' => StatusPrilike::Nacrt]],
            'arhivirana' => [['status' => StatusPrilike::Arhivirano]],
            'rok je prošao' => [['rok' => '2026-10-09']],
        ];
    }

    // Kad izabrana prilika prestane da bude javna, „Sada otvoreno" nestaje sam, a veza u bazi ostaje.
    /** @param  array<string, mixed>  $promena */
    #[Test]
    #[DataProvider('prilikeKojeViseNisuJavne')]
    public function oznaka_nestaje_sama_kad_izabrana_prilika_prestane_da_bude_javna(array $promena): void
    {
        $prilika = $this->prilika();
        $stavka = $this->stavka(['prilika_id' => $prilika->getKey()]);
        $this->assertStringContainsString('Sada otvoreno', $this->vidljivTekst($this->strana()));

        $prilika->forceFill($promena)->save();

        $html = $this->strana();

        $this->assertStringContainsString('Studentske stipendije 2025/26', $html);
        $this->assertStringNotContainsString('Sada otvoreno', $this->vidljivTekst($html));
        $this->assertStringNotContainsString(route('prilike.show', $prilika->slug), $html);
        $this->assertSame($prilika->getKey(), $this->vezaStavke($stavka));
    }

    // Stara pravila ostaju: isti link i dalje znači „sada otvoreno"; izabrana prilika ima prednost, a kad nije javna važi link.
    #[Test]
    public function pravilo_linka_vazi_kao_do_sada_a_izabrana_prilika_ima_prednost(): void
    {
        $istiLink = $this->prilika(['naslov' => 'Prilika sa istim linkom', 'link_izvora' => 'https://ministarstvo.example/konkurs-2025.pdf/']);
        $izabrana = $this->prilika(['naslov' => 'Ručno izabrana prilika']);
        $stavka = $this->stavka();

        $this->assertStringContainsString('Sada otvoreno: Prilika sa istim linkom', $this->vidljivTekst($this->strana()));

        $stavka->forceFill(['prilika_id' => $izabrana->getKey()])->save();

        $this->assertStringContainsString('Sada otvoreno: Ručno izabrana prilika', $this->vidljivTekst($this->strana()));
        $this->assertStringNotContainsString('Prilika sa istim linkom', $this->vidljivTekst($this->strana()));

        $izabrana->forceFill(['status' => StatusPrilike::Arhivirano])->save();

        $this->assertStringContainsString('Sada otvoreno: Prilika sa istim linkom', $this->vidljivTekst($this->strana()));

        $istiLink->forceFill(['status' => StatusPrilike::Nacrt])->save();

        $this->assertStringNotContainsString('Sada otvoreno', $this->vidljivTekst($this->strana()));
    }

    // Veza ne menja šta je u kalendaru: stavka čiji kraj nije prošao ostaje najava, pa je nema, ma koju priliku imala izabranu.
    #[Test]
    public function izabrana_prilika_ne_pravi_od_najave_stavku_kalendara(): void
    {
        $prilika = $this->prilika();
        $this->stavka(['naziv' => 'Još traje', 'otvoreno_do' => '2026-10-20', 'prilika_id' => $prilika->getKey()]);

        $html = $this->strana();

        $this->assertStringNotContainsString('Još traje', $html);
        $this->assertStringNotContainsString('Sada otvoreno', $this->vidljivTekst($html));
    }

    // Model je jedino mesto pravila: nova veza samo na javnu priliku; veza koja je već stajala ostaje kad se menja nešto drugo.
    #[Test]
    public function model_prima_novu_vezu_samo_na_javnu_priliku_a_staru_ostavlja(): void
    {
        $javna = $this->prilika();
        $nacrt = $this->prilika(['naslov' => 'Nacrt', 'status' => StatusPrilike::Nacrt, 'link_izvora' => 'https://ministarstvo.example/nacrt']);
        $stavka = $this->stavka();

        try {
            $stavka->update(['prilika_id' => $nacrt->getKey()]);
            $this->fail('Veza na nacrt je prošla.');
        } catch (ValidationException $izuzetak) {
            $this->assertSame([KalendarStavka::PORUKA_PRILIKA_NIJE_JAVNA], $izuzetak->errors()['prilika_id']);
        }

        $this->assertNull($this->vezaStavke($stavka));

        $stavka->update(['prilika_id' => $javna->getKey()]);
        $this->assertSame($javna->getKey(), $this->vezaStavke($stavka));

        // Prilika nestaje sa sajta, a stavka se menja zbog nečeg drugog: veza ostaje i ne smeta.
        $javna->forceFill(['status' => StatusPrilike::Arhivirano])->save();
        $stavka->fresh()?->update(['napomena' => 'Dopuna.']);

        $this->assertSame($javna->getKey(), $this->vezaStavke($stavka));
        $this->assertSame('Dopuna.', $stavka->fresh()->napomena);
    }

    // Kad se red prilike ukloni, veza nestaje a stavka ostaje (brisanje u aplikaciji ne postoji, pa se meri upitom).
    #[Test]
    public function uklonjena_prilika_ostavlja_stavku_bez_veze(): void
    {
        $prilika = $this->prilika();
        $stavka = $this->stavka(['prilika_id' => $prilika->getKey()]);

        DB::table('prilike')->where('id', $prilika->getKey())->delete();

        $this->assertNotNull(KalendarStavka::query()->find($stavka->getKey()));
        $this->assertNull($this->vezaStavke($stavka));
    }

    #[Test]
    public function admin_bira_javnu_priliku_i_ostavlja_polje_prazno(): void
    {
        $this->actingAs(User::factory()->create());
        $javna = $this->prilika();
        $nacrt = $this->prilika(['naslov' => 'Nacrt prilike', 'status' => StatusPrilike::Nacrt, 'link_izvora' => 'https://ministarstvo.example/nacrt']);
        $stavka = $this->stavka();

        Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()])->fillForm(['prilika_id' => $javna->getKey()])->call('save')->assertHasNoFormErrors();
        $this->assertSame($javna->getKey(), $this->vezaStavke($stavka));

        Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()])->fillForm(['prilika_id' => $nacrt->getKey()])->call('save')->assertHasFormErrors(['prilika_id']);
        $this->assertNotSame($nacrt->getKey(), $this->vezaStavke($stavka));
        $this->assertSame((string) $javna->getKey(), (string) $this->vezaStavke($stavka));

        Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()])->fillForm(['prilika_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->vezaStavke($stavka));

        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => 'Nova stavka', 'otvoreno_do' => '2026-01-26', 'prilika_id' => $javna->getKey()])->call('create')->assertHasNoFormErrors();
        $this->assertSame($javna->getKey(), KalendarStavka::query()->where('naziv', 'Nova stavka')->sole()->prilika_id);
    }

    // Polje nosi ime i jednu rečenicu objašnjenja (tekst je predlog, PITANJA-89.md); nudi samo javne prilike, a izabrana koja je nestala sa sajta i dalje se vidi po imenu.
    #[Test]
    public function polje_ima_ime_objasnjenje_nudi_samo_javne_prilike_i_cuva_ime_izabrane(): void
    {
        $this->actingAs(User::factory()->create());
        $javna = $this->prilika(['naslov' => 'Studentske stipendije 2026/27', 'naziv_izvora' => 'Ministarstvo prosvete']);
        $this->prilika(['naslov' => 'Studentski krediti', 'status' => StatusPrilike::Nacrt, 'link_izvora' => 'https://ministarstvo.example/nacrt']);
        $this->prilika(['naslov' => 'Studentski domovi', 'status' => StatusPrilike::Arhivirano, 'link_izvora' => 'https://ministarstvo.example/arhiva']);
        $this->prilika(['naslov' => 'Studentska praksa', 'rok' => '2026-10-09', 'link_izvora' => 'https://ministarstvo.example/prosla']);
        $stavka = $this->stavka(['prilika_id' => $javna->getKey()]);

        $stranica = Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()]);

        $stranica->assertFormFieldExists('prilika_id', function (Select $polje): bool {
            $this->assertSame('Sada otvoreno kao', $polje->getLabel());
            $this->assertFalse($polje->isRequired());
            $this->assertSame(['Studentske stipendije 2026/27 (Ministarstvo prosvete)'], array_values($polje->getOptions()));
            $this->assertSame(['Studentske stipendije 2026/27 (Ministarstvo prosvete)'], array_values($polje->getSearchResults('student')));
            $this->assertSame([], $polje->getSearchResults('krediti'));

            return true;
        });

        $stranica->assertSee('Sada otvoreno kao')->assertSee('Izaberi priliku sa sajta koja je ista kao ova stavka.')->assertSee('Kalendar tada piše „Sada otvoreno“ dok je prilika na sajtu.');

        // Izabrana prilika je u međuvremenu arhivirana: ime se i dalje vidi, a forma se snima bez greške.
        $javna->forceFill(['status' => StatusPrilike::Arhivirano])->save();

        Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()])
            ->assertFormFieldExists('prilika_id', fn (Select $polje): bool => $polje->getOptionLabel() === 'Studentske stipendije 2026/27 (Ministarstvo prosvete)')
            ->fillForm(['napomena' => 'Dopuna.'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($javna->getKey(), $this->vezaStavke($stavka));
    }
}

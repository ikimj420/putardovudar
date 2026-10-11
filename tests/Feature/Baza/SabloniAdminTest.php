<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Filament\Resources\Sabloni\Pages\CreateSablon;
use App\Filament\Resources\Sabloni\Pages\EditSablon;
use App\Filament\Resources\Sabloni\Pages\ListSabloni;
use App\Filament\Resources\Sabloni\SabloniResource;
use App\Models\Sablon;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 51: šabloni u adminu kao kartice (nacrt/objavljeno), sa poljima koja Ivan menja.
#[Group('baza')]
class SabloniAdminTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** @return list<array<string, mixed>> */
    private static function polja(): array
    {
        return [['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]];
    }

    #[Test]
    public function spisak_su_kartice_sa_statusom_brojem_polja_i_dugmetom_uredi(): void
    {
        $sablon = Sablon::factory()->create(['naslov' => 'Molba']);
        $bezPolja = Sablon::factory()->create(['naslov' => 'Bez polja', 'polja' => []]);

        $html = Livewire::test(ListSabloni::class)->assertCanSeeTableRecords([$sablon, $bezPolja])->html();

        $this->assertSame(2, substr_count($html, 'role="listitem"'));
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertSame(1, substr_count($html, '>Polja<'), 'red „Polja" crta samo kartica koja ima polja');

        $spisak = Livewire::test(ListSabloni::class);
        $spisak->assertSeeInOrder(['Molba', 'Nacrt', 'Polja', '2', 'Uredi']);
        $spisak->assertTableActionHasUrl('edit', SabloniResource::getUrl('edit', ['record' => $sablon]), record: $sablon);
    }

    #[Test]
    public function spisak_se_filtrira_po_statusu_i_pretrazuje_po_naslovu(): void
    {
        $nacrt = Sablon::factory()->create(['naslov' => 'Molba nacrt']);
        $objavljen = Sablon::factory()->objavljen()->create(['naslov' => 'Zahtev objavljen']);

        Livewire::test(ListSabloni::class)->filterTable('status', StatusObjave::Objavljeno->value)->assertCanSeeTableRecords([$objavljen])->assertCanNotSeeTableRecords([$nacrt]);
        Livewire::test(ListSabloni::class)->searchTable('Molba')->assertCanSeeTableRecords([$nacrt])->assertCanNotSeeTableRecords([$objavljen]);
    }

    #[Test]
    public function dodavanje_pravi_slug_iz_naslova_i_cuva_polja_kao_spisak(): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'Molba za pomoć', 'objasnjenje' => 'Za dopis.', 'polja' => self::polja(), 'tekst' => "{ime}\n\n{tekst}"])
            ->call('create')
            ->assertHasNoFormErrors();

        $sablon = Sablon::query()->sole();

        $this->assertSame('molba-za-pomoc', $sablon->slug);
        $this->assertSame(self::polja(), $sablon->polja);
        $this->assertSame(StatusObjave::Nacrt, $sablon->status);
        $this->assertSame(0, Sablon::javni()->count());
    }

    #[Test]
    public function naslov_objasnjenje_polja_i_tekst_su_obavezni(): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => '', 'objasnjenje' => '', 'polja' => [], 'tekst' => ''])
            ->call('create')
            ->assertHasFormErrors(['naslov' => 'required', 'objasnjenje' => 'required', 'polja' => 'required', 'tekst' => 'required']);

        $this->assertSame(0, Sablon::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function losiKljucevi(): array
    {
        return ['velika slova' => ['Ime'], 'razmak' => ['ime prezime'], 'počinje ciframa' => ['1ime'], 'crtica' => ['ime-prezime'], 'dijakritik' => ['šifra']];
    }

    #[Test]
    #[DataProvider('losiKljucevi')]
    public function kljuc_polja_je_mala_slova_cifre_i_donja_crta(string $kljuc): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'A', 'objasnjenje' => 'B', 'polja' => [['kljuc' => $kljuc, 'oznaka' => 'Natpis', 'dugo' => false]], 'tekst' => 'Bez mesta.'])
            ->call('create')
            ->assertHasFormErrors(['polja.0.kljuc']);

        $this->assertSame(0, Sablon::query()->count());
    }

    // Ogledalo: ispravni ključevi prolaze, a isti ključ dvaput ne.
    #[Test]
    public function ispravan_kljuc_prolazi_a_ponovljen_ne(): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'A', 'objasnjenje' => 'B', 'polja' => [['kljuc' => 'kome_pisem2', 'oznaka' => 'Kome', 'dugo' => false]], 'tekst' => '{kome_pisem2}'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'B', 'objasnjenje' => 'B', 'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'dugo' => false], ['kljuc' => 'ime', 'oznaka' => 'Ime opet', 'dugo' => false]], 'tekst' => '{ime}'])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(1, Sablon::query()->count());
    }

    // Mesto u tekstu bez polja se ne snima iz forme; ogledalo: tekst sa svim poljima prolazi.
    #[Test]
    public function mesto_u_tekstu_bez_polja_se_ne_snima(): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'A', 'objasnjenje' => 'B', 'polja' => self::polja(), 'tekst' => '{ime} {adresa}'])
            ->call('create')
            ->assertHasFormErrors(['tekst']);

        $this->assertSame(0, Sablon::query()->count());
    }

    // Oznaka roda {radio/radila} nije mesto za polje, pa provera ne traži polje za nju; {nepostoji} i dalje traži.
    #[Test]
    public function oznaka_roda_u_tekstu_ne_trazi_polje_a_mesto_bez_polja_i_dalje_trazi(): void
    {
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'A', 'objasnjenje' => 'B', 'polja' => self::polja(), 'tekst' => '{ime}, {radio/radila} sam u firmi.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Sablon::query()->count());

        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'C', 'objasnjenje' => 'D', 'polja' => self::polja(), 'tekst' => '{ime}, {radio/radila} sam u {nepostoji}.'])
            ->call('create')
            ->assertHasFormErrors(['tekst']);

        $this->assertSame(0, Sablon::query()->where('naslov', 'C')->count());
    }

    // Isto i mimo forme: objava šablona sa mestom bez polja ne prolazi, a nacrt se snima (Ivan ga još piše).
    #[Test]
    public function objava_mimo_forme_sa_mestom_bez_polja_ne_prolazi_a_nacrt_prolazi(): void
    {
        $nacrt = Sablon::factory()->create(['tekst' => '{ime} {adresa}']);

        $this->assertSame(StatusObjave::Nacrt, $nacrt->fresh()->status);

        try {
            $nacrt->update(['status' => StatusObjave::Objavljeno]);
            $this->fail('objava je prošla');
        } catch (ValidationException $izuzetak) {
            $this->assertSame([Sablon::PORUKA_NEPOZNATO_POLJE.'{adresa}'], $izuzetak->errors()['tekst']);
        }

        $nacrt->update(['tekst' => '{ime}', 'status' => StatusObjave::Objavljeno]);
        $this->assertSame(1, Sablon::javni()->count());
    }

    #[Test]
    public function izmena_menja_polja_tekst_i_status(): void
    {
        $sablon = Sablon::factory()->create();

        Livewire::test(EditSablon::class, ['record' => $sablon->getKey()])
            ->fillForm(['naslov' => 'Novi naslov', 'polja' => [self::polja()[0]], 'tekst' => 'Zdravo {ime}', 'status' => StatusObjave::Objavljeno])
            ->call('save')
            ->assertHasNoFormErrors();

        $posle = $sablon->fresh();

        $this->assertSame('Novi naslov', $posle->naslov);
        $this->assertSame([self::polja()[0]], $posle->polja);
        $this->assertSame(StatusObjave::Objavljeno, $posle->status);
        $this->assertSame(1, Sablon::javni()->count());
    }

    #[Test]
    public function polja_nose_oznaku_i_objasnjenje(): void
    {
        $stranica = Livewire::test(CreateSablon::class);

        foreach ([
            'Naslov' => 'Naslov šablona koji se vidi na spisku, npr. „Molba“.',
            'Slug' => 'Deo adrese strane; ako ostane prazno, pravi se iz naslova.',
            'Objašnjenje' => 'Jedna do dve rečenice za spisak, npr. „Za dopis u kojem nešto tražiš od ustanove.“',
            'Polja' => 'Šta čovek popunjava, redom; svako polje ima ključ koji u tekstu stoji u zagradama, npr. {ime}.',
            'Tekst' => 'Opšti oblik dopisa; mesto za polje je ključ u velikim zagradama, npr. {ime}, i svako mesto mora da ima svoje polje. Gde reč zavisi od roda, piši oba oblika ovako: {radio/radila}. Novi red je novi red na strani.',
            'Status' => 'Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu.',
        ] as $oznaka => $objasnjenje) {
            $this->assertSame(1, preg_match('#<span class="fi-fo-field-label-content">\s*'.preg_quote($oznaka, '#').'\s*(?:<[^>]+>[^<]*</[^>]+>\s*)?</span>#', $stranica->html()), $oznaka);
            $stranica->assertSee($objasnjenje);
        }
    }

    #[Test]
    public function brisanje_nije_ni_ponudjeno_ni_dozvoljeno(): void
    {
        $sablon = Sablon::factory()->create();
        $korisnik = User::factory()->create();

        $this->assertTrue($korisnik->can('update', $sablon));
        $this->assertFalse($korisnik->can('delete', $sablon));
        $this->assertFalse($korisnik->can('deleteAny', Sablon::class));
        Livewire::test(EditSablon::class, ['record' => $sablon->getKey()])->assertActionDoesNotExist('delete');
    }
}

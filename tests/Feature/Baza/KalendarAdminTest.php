<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Enums\StatusPrilike;
use App\Filament\Resources\Kalendar\KalendarResource;
use App\Filament\Resources\Kalendar\Pages\CreateKalendarStavka;
use App\Filament\Resources\Kalendar\Pages\EditKalendarStavka;
use App\Filament\Resources\Kalendar\Pages\ListKalendar;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Models\User;
use App\Services\KalendarIzPrilike;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 44: stavke kalendara u adminu (kartice, forma) i dugme „Dodaj u kalendar" na kartici prilike kojoj je prošao rok.
#[Group('baza')]
class KalendarAdminTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    #[Test]
    public function spisak_su_kartice_sa_nazivom_statusom_i_redovima_a_prazan_red_se_ne_crta(): void
    {
        $puna = KalendarStavka::factory()->zaGrupe(Grupa::Studenti)->create(['naziv' => 'Stipendija Dositeja', 'ko_daje' => 'Fond za mlade talente', 'otvoreno_od' => '2025-12-01', 'otvoreno_do' => '2026-01-26', 'link_izvora' => 'https://fond.rs/dositeja']);
        $gola = KalendarStavka::factory()->create(['naziv' => 'Gola', 'ko_daje' => null, 'otvoreno_do' => '2026-02-10', 'link_izvora' => null]);

        $html = Livewire::test(ListKalendar::class)->assertCanSeeTableRecords([$puna, $gola])->html();

        $this->assertSame(2, substr_count($html, 'role="listitem"'));
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);

        Livewire::test(ListKalendar::class)->assertSeeInOrder(['Stipendija Dositeja', 'Nacrt', 'Ko daje', 'Fond za mlade talente', 'Kome je namenjeno', 'Studenti', 'Otvoreno', '01.12.2025. – 26.01.2026.', 'Izvor', 'https://fond.rs/dositeja', 'Uredi']);

        // Ogledalo: stavka bez ko daje, grupa i linka ne crta te redove; bez početka piše samo kraj.
        Livewire::test(ListKalendar::class)->searchTable('Gola')->assertSee('10.02.2026.', false)->assertDontSee('Fond za mlade talente');
        $this->assertStringNotContainsString('>Ko daje<', Livewire::test(ListKalendar::class)->searchTable('Gola')->html());
        $this->assertStringNotContainsString('>Izvor<', Livewire::test(ListKalendar::class)->searchTable('Gola')->html());
    }

    #[Test]
    public function admin_dodaje_stavku_kao_nacrt_sa_grupama_u_redosledu_enuma(): void
    {
        Livewire::test(CreateKalendarStavka::class)
            ->fillForm(['naziv' => 'Stipendija Dositeja', 'ko_daje' => 'Fond', 'grupe' => ['romi', 'studenti'], 'otvoreno_do' => '2026-01-26'])
            ->call('create')
            ->assertHasNoFormErrors();

        $stavka = KalendarStavka::query()->sole();

        $this->assertSame(StatusObjave::Nacrt, $stavka->status);
        $this->assertSame(['studenti', 'romi'], $stavka->grupe);
        $this->assertSame('2026-01-26', $stavka->otvoreno_do->toDateString());
        $this->assertNull($stavka->otvoreno_od);
        $this->assertSame(0, KalendarStavka::javne()->count());
    }

    #[Test]
    public function naziv_i_kraj_su_obavezni_a_pocetak_ne_sme_posle_kraja(): void
    {
        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => '', 'otvoreno_do' => null])->call('create')->assertHasFormErrors(['naziv', 'otvoreno_do']);
        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => 'Loš redosled', 'otvoreno_od' => '2026-02-01', 'otvoreno_do' => '2026-01-26'])->call('create')->assertHasFormErrors(['otvoreno_od']);

        $this->assertSame(0, KalendarStavka::query()->count());

        // Ogledalo: početak isti kao kraj, i prazan početak, prolaze.
        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => 'Jedan dan', 'otvoreno_od' => '2026-01-26', 'otvoreno_do' => '2026-01-26'])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => 'Bez početka', 'otvoreno_do' => '2026-01-26'])->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, KalendarStavka::query()->count());
    }

    // Objava traži link izvora (isto pravilo kao za prilike); nacrt bez linka prolazi.
    #[Test]
    public function objava_bez_linka_izvora_ne_prolazi_a_nacrt_prolazi(): void
    {
        Livewire::test(CreateKalendarStavka::class)
            ->fillForm(['naziv' => 'Bez linka', 'otvoreno_do' => '2026-01-26', 'status' => StatusObjave::Objavljeno])
            ->call('create')
            ->assertHasFormErrors(['link_izvora' => KalendarStavka::PORUKA_BEZ_IZVORA]);
        $this->assertSame(0, KalendarStavka::query()->count());

        Livewire::test(CreateKalendarStavka::class)->fillForm(['naziv' => 'Nacrt bez linka', 'otvoreno_do' => '2026-01-26'])->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateKalendarStavka::class)
            ->fillForm(['naziv' => 'Sa linkom', 'otvoreno_do' => '2026-01-26', 'status' => StatusObjave::Objavljeno, 'link_izvora' => 'https://fond.rs/dositeja'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Sa linkom'], KalendarStavka::javne()->pluck('naziv')->all());
    }

    // I mimo forme: model ne dozvoljava objavu bez ispravnog linka.
    #[Test]
    public function model_ne_dozvoljava_objavu_bez_ispravnog_linka(): void
    {
        foreach ([null, '', 'nije link', 'javascript:alert(1)'] as $link) {
            try {
                KalendarStavka::factory()->objavljena()->create(['link_izvora' => $link]);
                $this->fail('Objava sa linkom „'.$link.'" je prošla.');
            } catch (ValidationException $izuzetak) {
                $this->assertSame([KalendarStavka::PORUKA_BEZ_IZVORA], $izuzetak->errors()['link_izvora']);
            }
        }

        $this->assertSame(0, KalendarStavka::query()->count());
        KalendarStavka::factory()->create(['link_izvora' => null]);
        $this->assertSame(1, KalendarStavka::query()->count());
    }

    #[Test]
    public function admin_menja_stavku(): void
    {
        $stavka = KalendarStavka::factory()->create(['naziv' => 'Stari naziv', 'otvoreno_do' => '2026-01-26']);

        Livewire::test(EditKalendarStavka::class, ['record' => $stavka->getKey()])
            ->fillForm(['naziv' => 'Novi naziv', 'grupe' => ['osnovci'], 'napomena' => 'Dopuna.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Novi naziv', $stavka->fresh()->naziv);
        $this->assertSame(['osnovci'], $stavka->fresh()->grupe);
        $this->assertSame('Dopuna.', $stavka->fresh()->napomena);
    }

    // Link izvora nema razmaka: red koji ga ispisuje se prelama gde god treba, da ne izlazi iz kartice na telefonu.
    #[Test]
    public function link_izvora_na_kartici_se_prelama(): void
    {
        $stavka = KalendarStavka::factory()->create(['link_izvora' => 'https://fond.rs/'.str_repeat('a', 300), 'ko_daje' => 'Fond']);

        $html = Livewire::test(ListKalendar::class)->assertCanSeeTableRecords([$stavka])->html();

        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        // Ogledalo: link i ko daje su na kartici, pa stil pokriva red koji ih ispisuje.
        $this->assertStringContainsString('https://fond.rs/'.str_repeat('a', 300), $html);
    }

    #[Test]
    public function polja_nose_oznaku_i_objasnjenje(): void
    {
        $stranica = Livewire::test(CreateKalendarStavka::class);

        foreach ([
            'Naziv' => 'Naziv prilike kakav je bio, npr. „Stipendija Dositeja“.',
            'Ko daje' => 'Ko je davao priliku, npr. „Fond za mlade talente“.',
            'Kome je namenjeno' => 'Izaberi jednu ili više grupa kojima je prilika bila namenjena; ostavi prazno ako se ne zna.',
            'Otvoreno od' => 'Prvi dan kada se moglo prijaviti; ostavi prazno ako se ne zna.',
            'Otvoreno do' => 'Poslednji dan kada se moglo prijaviti, npr. 26.01.2026.',
            'Link izvora' => 'Adresa zvaničnog izvora; bez nje se stavka ne može objaviti.',
            'Napomena' => 'Šta još treba znati o tom roku (vidi se na sajtu), npr. „Prijave su išle preko eUprave.“',
            'Status' => 'Nacrt se ne vidi javno, a Objavljeno se vidi u kalendaru na sajtu kad prođe poslednji dan roka; za objavu je potreban link izvora.',
        ] as $oznaka => $objasnjenje) {
            // Oznaka je sama u svom elementu (reč „Naziv" piše i u objašnjenjima), objašnjenje je uz polje.
            $this->assertSame(1, preg_match('#<span class="fi-fo-field-label-content">\s*'.preg_quote($oznaka, '#').'\s*(?:<[^>]+>[^<]*</[^>]+>\s*)?</span>#', $stranica->html()), $oznaka);
            $stranica->assertSee($objasnjenje);
        }
    }

    // --- „Dodaj u kalendar" na kartici prilike ---

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function rokovi(): array
    {
        return [
            'rok juče' => [['rok' => '2026-10-09'], true],
            'rok pre mesec dana' => [['rok' => '2026-09-10'], true],
            'rok danas' => [['rok' => '2026-10-10'], false],
            'rok sutra' => [['rok' => '2026-10-11'], false],
            'bez roka' => [['rok' => null], false],
            'stalno otvoreno sa starim rokom' => [['rok' => '2026-01-01', 'rok_stalno_otvoren' => true], false],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('rokovi')]
    public function dugme_je_samo_na_kartici_kojoj_je_rok_prosao(array $dopuna, bool $vidi): void
    {
        $prilika = Prilika::factory()->create($dopuna);

        $spisak = Livewire::test(ListPrilike::class);

        $vidi ? $spisak->assertTableActionVisible('dodajUKalendar', $prilika) : $spisak->assertTableActionHidden('dodajUKalendar', $prilika);
        $vidi ? $spisak->assertSee('Dodaj u kalendar') : $spisak->assertDontSee('Dodaj u kalendar');
    }

    // Dugme popunjava naziv, izvor, link, rok i grupe iz prilike; stavka je nacrt i vodi na izmenu.
    #[Test]
    public function dugme_popunjava_stavku_iz_prilike_kao_nacrt_i_vodi_na_izmenu(): void
    {
        $prilika = Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti, Grupa::Romi)->create([
            'naslov' => 'Stipendija Dositeja', 'naziv_izvora' => 'Fond za mlade talente', 'link_izvora' => 'https://fond.rs/dositeja', 'rok' => '2026-01-26',
        ]);

        $spisak = Livewire::test(ListPrilike::class)->callTableAction('dodajUKalendar', $prilika);

        $stavka = KalendarStavka::query()->sole();

        $spisak->assertNotified('Stavka je dodata u kalendar kao nacrt.')->assertRedirect(KalendarResource::getUrl('edit', ['record' => $stavka]));
        $this->assertSame('Stipendija Dositeja', $stavka->naziv);
        $this->assertSame('Fond za mlade talente', $stavka->ko_daje);
        $this->assertSame('https://fond.rs/dositeja', $stavka->link_izvora);
        $this->assertSame('2026-01-26', $stavka->otvoreno_do->toDateString());
        $this->assertSame(['studenti', 'romi'], $stavka->grupe);
        $this->assertNull($stavka->otvoreno_od);
        $this->assertNull($stavka->napomena);
        $this->assertSame(StatusObjave::Nacrt, $stavka->status);
        // Nacrt nije javan; prilika ostaje kakva je.
        $this->assertSame(0, KalendarStavka::javne()->count());
        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
    }

    // Drugi klik na istu priliku ne pravi drugu stavku, nego kaže da je već u kalendaru; druga prilika (drugi link) dobija svoju.
    #[Test]
    public function drugi_klik_ne_dodaje_istu_stvar_dvaput(): void
    {
        $prilika = Prilika::factory()->create(['naslov' => 'Prva', 'link_izvora' => 'https://fond.rs/dositeja', 'rok' => '2026-01-26']);
        $druga = Prilika::factory()->create(['naslov' => 'Druga', 'link_izvora' => 'https://fond.rs/druga', 'rok' => '2026-01-26']);

        Livewire::test(ListPrilike::class)->callTableAction('dodajUKalendar', $prilika);
        Livewire::test(ListPrilike::class)->callTableAction('dodajUKalendar', $prilika)->assertNotified('Ova prilika je već u kalendaru.');

        $this->assertSame(1, KalendarStavka::query()->count());

        Livewire::test(ListPrilike::class)->callTableAction('dodajUKalendar', $druga)->assertNotified('Stavka je dodata u kalendar kao nacrt.');

        $this->assertSame(['Prva', 'Druga'], KalendarStavka::query()->orderBy('id')->pluck('naziv')->all());
    }

    // Ista stvar je isti link izvora i isti rok: drugi rok istog linka je nova stavka, a bez linka isto važi za naziv (i drugi naziv).
    #[Test]
    public function isti_link_sa_drugim_rokom_i_bez_linka_drugi_naziv_daju_nove_stavke(): void
    {
        $usluga = app(KalendarIzPrilike::class);
        $prva = Prilika::factory()->create(['naslov' => 'Dositeja', 'link_izvora' => 'https://fond.rs/dositeja', 'rok' => '2026-01-26']);
        $sledeca = Prilika::factory()->create(['naslov' => 'Dositeja', 'link_izvora' => 'https://fond.rs/dositeja', 'rok' => '2025-01-26']);

        $this->assertTrue($usluga->dodaj($prva)->wasRecentlyCreated);
        $this->assertTrue($usluga->dodaj($sledeca)->wasRecentlyCreated);
        $this->assertFalse($usluga->dodaj($prva)->wasRecentlyCreated);
        $this->assertSame(2, KalendarStavka::query()->count());

        $bez = Prilika::factory()->create(['naslov' => 'Bez linka', 'link_izvora' => null, 'rok' => '2026-01-26']);
        $drugaBez = Prilika::factory()->create(['naslov' => 'Drugi naziv', 'link_izvora' => null, 'rok' => '2026-01-26']);

        $this->assertTrue($usluga->dodaj($bez)->wasRecentlyCreated);
        $this->assertFalse($usluga->dodaj($bez)->wasRecentlyCreated);
        $this->assertTrue($usluga->dodaj($drugaBez)->wasRecentlyCreated);
        $this->assertSame(4, KalendarStavka::query()->count());

        // Prilika bez linka sa istim nazivom i rokom kao stavka koja link ima je ista stvar.
        $this->assertFalse($usluga->dodaj(Prilika::factory()->create(['naslov' => 'Dositeja', 'link_izvora' => null, 'rok' => '2026-01-26']))->wasRecentlyCreated);
        $this->assertSame(4, KalendarStavka::query()->count());
    }

    #[Test]
    public function usluga_ne_prima_priliku_kojoj_rok_nije_prosao(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(KalendarIzPrilike::class)->dodaj(Prilika::factory()->create(['rok' => '2026-10-10']));
    }

    // Brava na radnji koja piše: kome politika ne dozvoljava dodavanje, dugme ne postoji, a ni direktan poziv (kao da ga šalje klijent
    // mimo ekrana) ništa ne pravi. Ogledalo: sa pravom dodavanja isti poziv pravi stavku.
    #[Test]
    public function bez_prava_dodavanja_dugme_se_ne_nudi_i_ne_radi(): void
    {
        $prilika = Prilika::factory()->create(['rok' => '2026-01-26']);

        Gate::policy(KalendarStavka::class, KalendarPolitikaBezDodavanja::class);

        $spisak = Livewire::test(ListPrilike::class)->assertTableActionHidden('dodajUKalendar', $prilika);
        $spisak->call('mountTableAction', 'dodajUKalendar', (string) $prilika->getKey())->call('callMountedTableAction');

        $this->assertSame(0, KalendarStavka::query()->count());
    }

    #[Test]
    public function sa_pravom_dodavanja_isti_direktan_poziv_pravi_stavku(): void
    {
        $prilika = Prilika::factory()->create(['rok' => '2026-01-26']);

        $spisak = Livewire::test(ListPrilike::class)->assertTableActionVisible('dodajUKalendar', $prilika);
        $spisak->call('mountTableAction', 'dodajUKalendar', (string) $prilika->getKey())->call('callMountedTableAction');

        $this->assertSame(1, KalendarStavka::query()->count());
    }
}

// Politika koja sve dozvoljava osim dodavanja: za test brave na dugmetu.
class KalendarPolitikaBezDodavanja
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return false;
    }
}

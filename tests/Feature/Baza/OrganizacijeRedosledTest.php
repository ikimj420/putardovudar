<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Filament\Resources\Organizacije\Pages\EditOrganizacija;
use App\Models\Organizacija;
use App\Models\User;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\PretragaOrganizacija;
use Database\Seeders\RomskeOrganizacijeSeeder;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 75/T2 (Ivan, 13.10.2026.): polje „Redosled" uz organizaciju; manji broj ide gore, prazno posle, pa po nazivu kao do sada; isto na strani „Organizacije" i u pomoćniku.
#[Group('baza')]
class OrganizacijeRedosledTest extends AdminBazaTestCase
{
    private const SESTORKA = [
        'Opre Roma Srbija', 'ERIAC – Evropski romski institut za umetnost i kulturu', 'Roma Education Fund (REF)', 'REDI – Roma Entrepreneurship Development Initiative',
        'Roma Foundation for Europe', 'Roma for Democracy',
    ];

    /** @return list<string> */
    private function nazivi(): array
    {
        return Organizacija::javne()->poRedosledu()->pluck('naziv')->all();
    }

    #[Test]
    public function nova_organizacija_nema_redosled(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create();

        $this->assertNull($organizacija->fresh()->redosled);
    }

    // Manji broj gore, prazan posle svih sa brojem, a unutar iste vrednosti po nazivu (dosadašnji red).
    #[Test]
    public function redosled_je_manji_broj_gore_prazno_posle_pa_po_nazivu(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Banka bez broja']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Zeta', 'redosled' => 3]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Yota', 'redosled' => 1]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Aaa bez broja']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Mika', 'redosled' => 2]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Beta', 'redosled' => 2]);

        $this->assertSame(['Yota', 'Beta', 'Mika', 'Zeta', 'Aaa bez broja', 'Banka bez broja'], $this->nazivi());
    }

    // Ogledalo: bez ijednog broja je isto što i do sada, po nazivu.
    #[Test]
    public function bez_brojeva_je_redosled_po_nazivu_kao_do_sada(): void
    {
        foreach (['Zadnja', 'Prva', 'Srednja'] as $naziv) {
            Organizacija::factory()->objavljena()->create(['naziv' => $naziv]);
        }

        $this->assertSame(['Prva', 'Srednja', 'Zadnja'], $this->nazivi());
    }

    // Strana „Organizacije": šest iz seedera na vrhu, redom, pa sve ostale po nazivu. Nacrt sa brojem 1 se ne vidi.
    #[Test]
    public function strana_organizacije_pokazuje_sest_na_vrhu_redom_a_ostale_ispod(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Aaa prva po abecedi']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Zzz poslednja po abecedi']);
        Organizacija::factory()->create(['naziv' => 'Nacrt sa brojem', 'redosled' => 1, 'status' => StatusObjave::Nacrt]);
        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->get(route('organizacije.index'))
            ->assertOk()
            ->assertSeeInOrder([...self::SESTORKA, 'Aaa prva po abecedi', 'Zzz poslednja po abecedi'])
            ->assertDontSee('Nacrt sa brojem');
    }

    // Seeder upisuje 1–6 tačno tim redom; broj je u bazi, ne u kodu strane.
    #[Test]
    public function seeder_upisuje_redosled_od_jedan_do_sest(): void
    {
        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertSame(
            array_combine(self::SESTORKA, [1, 2, 3, 4, 5, 6]),
            Organizacija::query()->orderBy('redosled')->pluck('redosled', 'naziv')->all(),
        );
        $this->assertSame(self::SESTORKA, $this->nazivi());
    }

    // Ogledalo: Ivanov broj na postojećem slugu seeder ne gazi, ni kad ga drugi put pokrene.
    #[Test]
    public function seeder_ne_menja_redosled_koji_je_ivan_postavio(): void
    {
        $this->seed(RomskeOrganizacijeSeeder::class);
        Organizacija::query()->where('slug', 'roma-for-democracy')->update(['redosled' => 1]);
        Organizacija::query()->where('slug', 'opre-roma-srbija')->update(['redosled' => null]);

        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertSame(1, Organizacija::query()->where('slug', 'roma-for-democracy')->sole()->redosled);
        $this->assertNull(Organizacija::query()->where('slug', 'opre-roma-srbija')->sole()->redosled);
    }

    // Pomoćnik ređa isto kao strana: sa brojem prve, i kad bi po nazivu bile daleko (kandidata je ograničen broj).
    #[Test]
    public function pomocnik_reda_organizacije_isto_kao_strana(): void
    {
        foreach (['Sedma', 'Prva', 'Peta', 'Treca', 'Sesta', 'Cetvrta', 'Druga'] as $naziv) {
            Organizacija::factory()->objavljena()->create(['naziv' => $naziv.' organizacija']);
        }
        Organizacija::factory()->objavljena()->create(['naziv' => 'Zzz organizacija', 'redosled' => 1]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Yyy organizacija', 'redosled' => 2]);

        $nazivi = app(PretragaOrganizacija::class)->pronadji(new Formular(kljucneReci: ['organizacije']))->pluck('naziv')->all();

        $this->assertSame(['Zzz organizacija', 'Yyy organizacija', 'Cetvrta organizacija', 'Druga organizacija', 'Peta organizacija'], $nazivi);
    }

    // Isto kad se traži po usluzi: onih sa brojem je prvo, najviše tri.
    #[Test]
    public function pomocnik_pri_pretrazi_po_usluzi_prvo_daje_one_sa_brojem(): void
    {
        foreach (['Peta za CV', 'Prva za CV', 'Druga za CV'] as $naziv) {
            Organizacija::factory()->objavljena()->create(['naziv' => $naziv, 'usluge' => ['CV podrška']]);
        }
        Organizacija::factory()->objavljena()->create(['naziv' => 'Zadnja za CV', 'usluge' => ['CV podrška'], 'redosled' => 1]);

        $nazivi = app(PretragaOrganizacija::class)->pronadji(new Formular(kljucneReci: ['CV']))->pluck('naziv')->all();

        $this->assertSame(['Zadnja za CV', 'Druga za CV', 'Peta za CV'], $nazivi);
    }

    // Admin: polje „Redosled" se menja u formi; novi broj odmah menja redosled na strani, a prazno vraća organizaciju iza ostalih.
    #[Test]
    public function ivan_menja_redosled_u_adminu_i_strana_ga_prati(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seed(RomskeOrganizacijeSeeder::class);
        $roma = Organizacija::query()->where('slug', 'roma-for-democracy')->sole();

        Livewire::test(EditOrganizacija::class, ['record' => $roma->getKey()])
            ->assertFormSet(['redosled' => 6])
            ->assertSee('manji broj ide gore na strani „Organizacije“')
            ->fillForm(['redosled' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        // Isti broj kao Opre Roma Srbija (1): ona je prva po nazivu, pa je ova odmah iza nje, ispred ERIAC-a (2).
        $this->assertSame(1, $roma->fresh()->redosled);
        $this->assertSame(['Opre Roma Srbija', 'Roma for Democracy', 'ERIAC – Evropski romski institut za umetnost i kulturu'], array_slice($this->nazivi(), 0, 3));

        Livewire::test(EditOrganizacija::class, ['record' => $roma->getKey()])->fillForm(['redosled' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($roma->fresh()->redosled);
        $this->assertSame('Roma for Democracy', $this->nazivi()[5]);
    }

    /** @return array<string, array{0: mixed}> vrednosti koje polje ne prima */
    public static function nevazeciBrojevi(): array
    {
        return ['nula' => [0], 'negativan' => [-1], 'preveliki' => [10000], 'slova' => ['prvi'], 'razlomak' => [2.5]];
    }

    #[Test]
    #[DataProvider('nevazeciBrojevi')]
    public function polje_redosled_prima_samo_ceo_broj_od_jedan_do_9999(mixed $vrednost): void
    {
        $this->actingAs(User::factory()->create());
        $organizacija = Organizacija::factory()->objavljena()->create(['redosled' => 4]);

        Livewire::test(EditOrganizacija::class, ['record' => $organizacija->getKey()])->fillForm(['redosled' => $vrednost])->call('save')->assertHasFormErrors(['redosled']);

        $this->assertSame(4, $organizacija->fresh()->redosled);
    }
}

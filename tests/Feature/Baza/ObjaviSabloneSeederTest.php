<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Filament\Resources\Sabloni\Pages\EditSablon;
use App\Models\Sablon;
use App\Models\User;
use Database\Seeders\ObjaviSabloneSeeder;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 103/T2: seeder objavljuje šest odobrenih šablona, ali samo nacrt koji je isti kao u VodiciPrimeriSeeder. Zatečeno stanje je ono iz
// radne baze 05.11.2026. (samo čitanje): svih šest su nacrti koje je napravio VodiciPrimeriSeeder, isti kao u njemu.
#[Group('baza')]
class ObjaviSabloneSeederTest extends AdminBazaTestCase
{
    private const SLUGOVI = ['email-uz-prijavu', 'motivaciono-pismo', 'cv', 'molba-za-preporuku', 'pitanje-o-dokumentu', 'moje-predstavljanje'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
        (new VodiciPrimeriSeeder)->run();
        // Sat ide napred, pa bi svaki upis seedera promenio i vreme izmene.
        $this->travel(1)->hours();
    }

    private function pusti(): string
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => ObjaviSabloneSeeder::class]));
        // Uz red seedera komanda ispisuje i svoje redove („RUNNING", „DONE"); seeder piše tačno jedan.
        $redovi = array_values(preg_grep('/^Objavljeno /', explode("\n", Artisan::output())) ?: []);
        $this->assertCount(1, $redovi);

        return $redovi[0];
    }

    /** @return array<string, array<string, mixed>> slug → kolone onako kako stoje u bazi */
    private function stanje(): array
    {
        return Sablon::query()->orderBy('id')->get()->mapWithKeys(fn (Sablon $sablon) => [$sablon->slug => $sablon->getAttributes()])->all();
    }

    /** @return list<string> */
    private function javni(): array
    {
        return Sablon::javni()->orderBy('id')->pluck('slug')->all();
    }

    #[Test]
    public function nacrt_isti_kao_u_seederu_se_objavljuje_a_menja_se_samo_status(): void
    {
        $tudji = Sablon::factory()->create(['slug' => 'moj-sablon']);
        $pre = $this->stanje();
        $this->assertCount(7, $pre);
        $this->assertSame([], $this->javni());

        $this->assertSame('Objavljeno 6, preskočeno 0.', $this->pusti());

        $this->assertSame(self::SLUGOVI, $this->javni());
        // Ogledalo: šablon kog nema u seederu ostaje nacrt i netaknut.
        $this->assertSame(StatusObjave::Nacrt, $tudji->fresh()->status);
        $posle = $this->stanje();
        $this->assertSame($pre['moj-sablon'], $posle['moj-sablon']);
        foreach (self::SLUGOVI as $slug) {
            $this->assertSame(['status' => 'objavljeno', 'updated_at' => '2026-11-05 13:00:00'], array_diff_assoc($posle[$slug], $pre[$slug]), $slug);
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function izmene(): array
    {
        $cv = collect(VodiciPrimeriSeeder::sabloni())->firstWhere('slug', 'cv');
        $polja = $cv['polja'];
        $polja[7]['dugo'] = false;

        return [
            'naslov' => [['naslov' => 'Moj CV'], 'naslov'],
            'objašnjenje' => [['objasnjenje' => 'Prvi CV.'], 'objašnjenje'],
            'jedno polje' => [['polja' => $polja], 'polja'],
            'tekst' => [['tekst' => $cv['tekst']."\nHvala."], 'tekst'],
            'naslov i tekst' => [['naslov' => 'Moj CV', 'tekst' => str_replace('Cilj', 'Moj cilj', $cv['tekst'])], 'naslov, tekst'],
        ];
    }

    // Šablon koji je Ivan menjao ostaje nacrt, tačno kakav je; ostali se objavljuju.
    /** @param  array<string, mixed>  $izmena */
    #[Test]
    #[DataProvider('izmene')]
    public function menjan_sablon_se_preskace_i_ostaje_kakav_je(array $izmena, string $razlike): void
    {
        Sablon::query()->where('slug', 'cv')->firstOrFail()->update($izmena);
        $pre = $this->stanje()['cv'];

        $this->assertSame('Objavljeno 5, preskočeno 1 (cv: razlikuje se od seedera: '.$razlike.').', $this->pusti());

        $this->assertSame($pre, $this->stanje()['cv']);
        $this->assertSame(array_values(array_diff(self::SLUGOVI, ['cv'])), $this->javni());
    }

    // Čuvanje u adminu bez ijedne izmene dopiše polju prazne vrednosti (opciono, ako_prazno, prazno objašnjenje postaje null): sadržaj je isti,
    // ali poređenje je strogo, pa ni takav šablon seeder ne objavljuje.
    #[Test]
    public function sablon_sacuvan_u_adminu_bez_izmene_ostaje_nacrt(): void
    {
        $this->actingAs(User::factory()->create());
        $sablon = Sablon::query()->where('slug', 'motivaciono-pismo')->firstOrFail();
        Livewire::test(EditSablon::class, ['record' => $sablon->getKey()])->call('save')->assertHasNoFormErrors();
        $izSeedera = collect(VodiciPrimeriSeeder::sabloni())->firstWhere('slug', 'motivaciono-pismo')['polja'];
        $bezPraznih = fn (array $polja) => array_map(fn (array $polje) => array_filter($polje, fn (mixed $vrednost) => ! in_array($vrednost, [null, false, ''], true)), $polja);
        $this->assertNotSame($izSeedera, $sablon->fresh()->polja);
        $this->assertEquals($bezPraznih($izSeedera), $bezPraznih($sablon->fresh()->polja));
        $pre = $this->stanje()['motivaciono-pismo'];

        $this->assertSame('Objavljeno 5, preskočeno 1 (motivaciono-pismo: razlikuje se od seedera: polja).', $this->pusti());

        $this->assertSame($pre, $this->stanje()['motivaciono-pismo']);
    }

    #[Test]
    public function vec_objavljen_sablon_se_preskace_i_ostaje_kakav_je(): void
    {
        Sablon::query()->where('slug', 'email-uz-prijavu')->firstOrFail()->update(['status' => StatusObjave::Objavljeno]);
        Sablon::query()->where('slug', 'cv')->firstOrFail()->update(['status' => StatusObjave::Objavljeno, 'naslov' => 'Moj CV']);
        $this->travel(1)->hours();
        $pre = $this->stanje();

        $this->assertSame('Objavljeno 4, preskočeno 2 (email-uz-prijavu: već je objavljen; cv: već je objavljen).', $this->pusti());

        $posle = $this->stanje();
        $this->assertSame($pre['email-uz-prijavu'], $posle['email-uz-prijavu']);
        $this->assertSame($pre['cv'], $posle['cv']);
        $this->assertSame(self::SLUGOVI, $this->javni());
    }

    #[Test]
    public function sablon_kog_nema_u_bazi_se_ne_pravi(): void
    {
        Sablon::query()->where('slug', 'moje-predstavljanje')->delete();

        $this->assertSame('Objavljeno 5, preskočeno 1 (moje-predstavljanje: nema ga u bazi).', $this->pusti());

        $this->assertSame(5, Sablon::query()->count());
        $this->assertNull(Sablon::query()->where('slug', 'moje-predstavljanje')->first());
    }

    #[Test]
    public function drugo_pustanje_ne_menja_nista(): void
    {
        $this->assertSame('Objavljeno 6, preskočeno 0.', $this->pusti());
        $this->travel(1)->hours();
        $pre = $this->stanje();

        $this->assertSame('Objavljeno 0, preskočeno 6 ('.implode('; ', array_map(fn (string $slug) => $slug.': već je objavljen', self::SLUGOVI)).').', $this->pusti());

        $this->assertSame($pre, $this->stanje());
    }
}

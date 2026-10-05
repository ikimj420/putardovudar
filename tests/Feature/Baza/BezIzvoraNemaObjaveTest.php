<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class BezIzvoraNemaObjaveTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('linkoviKojiNisuLink')]
    public function objava_bez_linka_izvora_je_odbijena(?string $link): void
    {
        try {
            Prilika::factory()->objavljena()->create(['link_izvora' => $link]);
            $this->fail('Objava bez ispravnog linka nije odbijena.');
        } catch (ValidationException $izuzetak) {
            $this->assertSame(['link_izvora' => ['Za objavu je potreban link izvora.']], $izuzetak->errors());
        }

        $this->assertSame(0, Prilika::query()->count());
    }

    /** @return array<string, array{0: string|null}> */
    public static function linkoviKojiNisuLink(): array
    {
        return [
            'nema ga' => [null],
            'prazan' => [''],
            'samo razmaci' => ['   '],
            'nije adresa' => ['nije link'],
            'nije veb adresa' => ['ftp://primer.rs/konkurs'],
            'veb adresa bez sajta' => ['https://'],
            'veb adresa sa razmakom' => ['http://primer rs/konkurs'],
        ];
    }

    // Ogledalo: isti zapis sa linkom prolazi i čuva se objavljen.
    #[Test]
    public function objava_sa_linkom_izvora_prolazi(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['link_izvora' => 'https://primer.rs/konkurs']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
        $this->assertSame('https://primer.rs/konkurs', $prilika->fresh()->link_izvora);
    }

    // Ogledalo: nacrt i arhiva ne traže izvor, pa pravilo ne sme da zatvori sve.
    #[Test]
    public function nacrt_i_arhivirana_prilika_mogu_bez_linka(): void
    {
        $nacrt = Prilika::factory()->create(['link_izvora' => null]);
        $arhivirana = Prilika::factory()->arhivirana()->create(['link_izvora' => null]);

        $this->assertNull($nacrt->fresh()->link_izvora);
        $this->assertNull($arhivirana->fresh()->link_izvora);
    }

    #[Test]
    public function nacrt_bez_linka_ne_moze_u_objavljeno_a_sa_linkom_moze(): void
    {
        $prilika = Prilika::factory()->create(['link_izvora' => null]);

        try {
            $prilika->update(['status' => StatusPrilike::Objavljeno]);
            $this->fail('Objava bez linka nije odbijena.');
        } catch (ValidationException) {
            $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
        }

        $prilika->update(['status' => StatusPrilike::Objavljeno, 'link_izvora' => 'https://primer.rs/konkurs']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
    }

    #[Test]
    public function objavljenoj_prilici_se_ne_moze_oduzeti_link(): void
    {
        $prilika = Prilika::factory()->objavljena()->create();

        $this->expectException(ValidationException::class);
        $prilika->update(['link_izvora' => null]);
    }

    #[Test]
    public function samo_objavljeno_trazi_izvor(): void
    {
        $traze = array_filter(StatusPrilike::cases(), fn (StatusPrilike $status) => $status->zahtevaIzvor());

        $this->assertSame([StatusPrilike::Objavljeno], array_values($traze));
    }
}

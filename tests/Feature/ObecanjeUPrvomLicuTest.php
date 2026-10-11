<?php

namespace Tests\Feature;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Services\Pomocnik\ProveraOdgovora;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 67/T16 (Ivan, 11.10.2026.): pomoćnik ništa ne šalje i ne pamti, pa odgovor sa obećanjem u prvom licu ne prolazi proveru, sa kalendarom i bez njega.
class ObecanjeUPrvomLicuTest extends TestCase
{
    private const RAZLOG = 'obećanje u prvom licu';

    private function prilika(): Prilika
    {
        return (new Prilika)->forceFill(['naslov' => 'Radnik u skladištu', 'slug' => 'radnik-u-skladistu', 'rok' => '2026-11-15']);
    }

    /** @return array<string, array{0: string}> */
    public static function obecanja(): array
    {
        return [
            'javiću ti' => ['Našao sam posao u Nišu. Javiću ti kad se pojavi nešto novo.'],
            'poslaću' => ['Našao sam stipendiju. Poslaću ti link.'],
            'obavestiću' => ['Našao sam konkurs. Obavestiću te čim se otvori.'],
            'podsetiću' => ['Našao sam konkurs. Podsetiću te pred rok.'],
            'ja ću ti javiti' => ['Našao sam posao. Ja ću ti javiti sve što saznam.'],
            'ću iza glagola' => ['Našao sam posao. Javiti ću ti.'],
            'velikim slovima' => ['NAŠAO SAM POSAO. JAVIĆU TI.'],
            'proveriću' => ['Našao sam posao. Proveriću još i to.'],
            'pozvaću' => ['Našao sam posao. Pozvaću ih umesto tebe.'],
            'pokrenuću' => ['Našao sam posao. Pokrenuću prijavu za tebe.'],
            'u sredini rečenice' => ['Našao sam posao pa ću ti javiti.'],
        ];
    }

    #[Test]
    #[DataProvider('obecanja')]
    public function obecanje_ne_prolazi_bez_kalendara(string $odgovor): void
    {
        $this->assertSame(self::RAZLOG, (new ProveraOdgovora)->razlogOdbijanja($odgovor, new Collection([$this->prilika()]), new Collection, new Collection, new Collection));
    }

    #[Test]
    public function obecanje_ne_prolazi_ni_uz_kalendar(): void
    {
        $this->assertSame(self::RAZLOG, (new ProveraOdgovora)->razlogOdbijanja('Našao sam konkurs. Javiću ti kad se otvori.', new Collection, new Collection, new Collection, new Collection([new KalendarStavka])));
    }

    /** @return array<string, array{0: string}> */
    public static function bezObecanja(): array
    {
        return [
            'rok' => ['Rok je 15.11.2026.'],
            'našao sam' => ['Našao sam posao radnika u skladištu u Nišu.'],
            'odeća i obuća' => ['Našao sam konkurs za odeću i obuću.'],
            'pomoću' => ['Prijavi se pomoću linka ispod.'],
            'noću' => ['Našao sam posao radnika u skladištu, radi se i noću.'],
            'veću' => ['Našao sam posao. Ovde imaš veću šansu.'],
            'kuću i sreću' => ['Našao sam pomoć za kuću. Želim ti sreću.'],
            'hoću' => ['Našao sam posao. Ako hoću da se prijavim, kako?'],
            'treće lice' => ['Našao sam posao. Poslodavac će te pozvati.'],
        ];
    }

    // Ogledalo: obična rečenica i reči na „-ću" koje nisu obećanje prolaze (rečenica „će" je posao druge provere, ovde se ne meri).
    #[Test]
    #[DataProvider('bezObecanja')]
    public function bez_obecanja_ne_odbija_zbog_obecanja(string $odgovor): void
    {
        $this->assertNotSame(self::RAZLOG, (new ProveraOdgovora)->razlogOdbijanja($odgovor, new Collection([$this->prilika()]), new Collection, new Collection, new Collection));
    }

    #[Test]
    public function rok_i_nasao_sam_prolaze_u_celini(): void
    {
        foreach (['Rok je 15.11.2026.', 'Našao sam posao radnika u skladištu u Nišu.'] as $odgovor) {
            $this->assertNull((new ProveraOdgovora)->razlogOdbijanja($odgovor, new Collection([$this->prilika()]), new Collection, new Collection, new Collection), $odgovor);
        }
    }
}

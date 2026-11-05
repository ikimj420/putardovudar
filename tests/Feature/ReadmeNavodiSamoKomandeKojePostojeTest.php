<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TekstoviZaPosetioca;
use Tests\TestCase;

// Paket 91: README je na srpskom, jednostavan, a svaka komanda, seeder, skripta i podešavanje koje navodi postoji; nijedna vrednost iz .env ne stoji u njemu.
class ReadmeNavodiSamoKomandeKojePostojeTest extends TestCase
{
    use TekstoviZaPosetioca;

    private function readme(): string
    {
        return (string) file_get_contents(base_path('README.md'));
    }

    /** @return list<string> sve što stoji između kosih navodnika */
    private function isecci(string $tekst): array
    {
        preg_match_all('/`([^`\n]+)`/u', $tekst, $nalazi);

        return $nalazi[1];
    }

    /** @return list<string> ključevi iz .env.example (bez vrednosti) */
    private function kljuceviIzPrimera(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $nalazi);

        return $nalazi[1];
    }

    /** @return list<string> vrednosti iz .env.example koje liče na prave (sa ciframa, tačkom ili dvotačkom), a ne na opšte reči */
    private function vrednostiIzPrimera(): array
    {
        preg_match_all('/^[A-Z][A-Z0-9_]*=(.+)$/m', (string) file_get_contents(base_path('.env.example')), $nalazi);

        return array_values(array_filter(array_map(fn (string $vrednost) => trim($vrednost, "\"' "), $nalazi[1]), fn (string $vrednost) => strlen($vrednost) >= 4 && preg_match('/[0-9.:]/', $vrednost) === 1));
    }

    /** @return list<string> */
    private function skripteKompozera(): array
    {
        return array_keys((array) (json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'] ?? []));
    }

    /** @return list<string> šta u README-u ne postoji: komanda, njena opcija, seeder, skripta ili podešavanje */
    private function problemi(string $readme): array
    {
        $problemi = [];
        $komande = Artisan::all();
        $kljucevi = $this->kljuceviIzPrimera();

        foreach ($this->isecci($readme) as $isecak) {
            $isecak = (string) preg_replace('/^php artisan /', '', $isecak);

            if (preg_match('/^([a-z][a-z-]*:[a-z-]+|serve)(?: (.*))?$/', $isecak, $deo) === 1) {
                if (! isset($komande[$deo[1]])) {
                    $problemi[] = 'nema komande '.$deo[1];

                    continue;
                }

                preg_match_all('/--([a-z-]+)/', $deo[2] ?? '', $opcije);

                foreach ($opcije[1] as $opcija) {
                    if (! $komande[$deo[1]]->getDefinition()->hasOption($opcija)) {
                        $problemi[] = 'komanda '.$deo[1].' nema opciju --'.$opcija;
                    }
                }
            } elseif (preg_match('/^[A-Z][A-Za-z]*Seeder$/', $isecak) === 1) {
                if (! class_exists('Database\\Seeders\\'.$isecak)) {
                    $problemi[] = 'nema seedera '.$isecak;
                }
            } elseif (preg_match('/^[A-Z][A-Z0-9_]+$/', $isecak) === 1) {
                if (! in_array($isecak, $kljucevi, true)) {
                    $problemi[] = 'nema podešavanja '.$isecak.' u .env.example';
                }
            } elseif (preg_match('/^composer ([a-z-]+)$/', $isecak, $deo) === 1) {
                if (! in_array($deo[1], $this->skripteKompozera(), true)) {
                    $problemi[] = 'nema skripte composer '.$deo[1];
                }
            }
        }

        return $problemi;
    }

    /** @return list<string> razlozi zbog kojih tekst izvan kodnih isečaka nije jednostavan */
    private function razloziProze(string $readme): array
    {
        $proza = (string) preg_replace('/```.*?```/s', '', $readme);
        $proza = (string) preg_replace('/`[^`\n]*`/u', '', $proza);
        $razlozi = [];

        foreach (explode("\n", $proza) as $red) {
            $red = trim((string) preg_replace('/^(?:#+|-|\d+\.)\s*/u', '', trim($red)));

            if ($red !== '') {
                array_push($razlozi, ...$this->razloziZaNalaz($red));
            }
        }

        return $razlozi;
    }

    #[Test]
    public function svaka_komanda_opcija_seeder_skripta_i_podesavanje_iz_readme_a_postoji(): void
    {
        $readme = $this->readme();

        $this->assertSame([], $this->problemi($readme));

        // Dokaz da je merilo obišlo neprazan skup: README navodi komande, seedere, podešavanja i skripte.
        $this->assertGreaterThanOrEqual(25, count($this->isecci($readme)));
        $komande = array_filter($this->isecci($readme), fn (string $isecak) => preg_match('/^[a-z][a-z-]*:[a-z-]+/', $isecak) === 1);
        $this->assertGreaterThanOrEqual(8, count($komande));
        $this->assertGreaterThanOrEqual(6, count(array_filter($this->isecci($readme), fn (string $isecak) => str_ends_with($isecak, 'Seeder'))));
        $this->assertGreaterThanOrEqual(12, count(array_filter($this->isecci($readme), fn (string $isecak) => preg_match('/^[A-Z][A-Z0-9_]+$/', $isecak) === 1)));
    }

    // Ogledalo: merilo odbija izmišljenu komandu, opciju, seeder, skriptu i podešavanje, a pušta pravu.
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function izmisljeniPrimeri(): array
    {
        return [
            'prava komanda i opcija' => ['`uvoz:obradi --ponovo` i `php artisan serve`', []],
            'izmišljena komanda' => ['`uvoz:nepostoji`', ['nema komande uvoz:nepostoji']],
            'izmišljena opcija' => ['`uvoz:obradi --nepostoji`', ['komanda uvoz:obradi nema opciju --nepostoji']],
            'izmišljen seeder' => ['`NepostojiSeeder`', ['nema seedera NepostojiSeeder']],
            'pravi seeder' => ['`RomskeOrganizacijeSeeder`', []],
            'izmišljeno podešavanje' => ['`NEPOSTOJI_KLJUC`', ['nema podešavanja NEPOSTOJI_KLJUC u .env.example']],
            'pravo podešavanje' => ['`ANTHROPIC_API_KEY`', []],
            'izbačeno podešavanje' => ['`MODEL_OBRADE`', ['nema podešavanja MODEL_OBRADE u .env.example']],
            'izmišljena skripta' => ['`composer nepostoji`', ['nema skripte composer nepostoji']],
            'prava skripta' => ['`composer test`', []],
        ];
    }

    /** @param  list<string>  $ocekivano */
    #[Test]
    #[DataProvider('izmisljeniPrimeri')]
    public function merilo_odbija_izmisljeno_a_pusta_pravo(string $readme, array $ocekivano): void
    {
        $this->assertSame($ocekivano, $this->problemi($readme));
    }

    // Pokrivenost: svaka komanda iz aplikacije i svaki seeder (osim podrazumevanog, koji pravi probnog korisnika) stoji u README-u, pa nova komanda traži izmenu README-a.
    #[Test]
    public function readme_navodi_svaku_komandu_iz_aplikacije_i_svaki_seeder(): void
    {
        $isecci = $this->isecci($this->readme());
        $naSpisku = 0;

        foreach (Artisan::all() as $ime => $komanda) {
            if (str_starts_with($komanda::class, 'App\\Console\\Commands\\')) {
                $naSpisku++;
                $this->assertTrue((bool) array_filter($isecci, fn (string $isecak) => str_starts_with((string) preg_replace('/^php artisan /', '', $isecak), $ime)), 'README ne navodi komandu '.$ime);
            }
        }

        $this->assertGreaterThanOrEqual(7, $naSpisku);

        $seederi = array_map(fn (string $putanja) => basename($putanja, '.php'), glob(database_path('seeders/*Seeder.php')) ?: []);
        $this->assertGreaterThanOrEqual(7, count($seederi));

        foreach (array_diff($seederi, ['DatabaseSeeder']) as $seeder) {
            $this->assertContains($seeder, $isecci, 'README ne navodi seeder '.$seeder);
        }
    }

    // Komande koje je Ivan naveo u paketu moraju da stoje u README-u.
    #[Test]
    public function readme_ima_komande_iz_paketa_91(): void
    {
        $readme = $this->readme();

        foreach (['`uvoz:dnevno`', '`uvoz:obradi --ponovo`', '`kalendar:iz-prilika`', '`sabloni:prazna-polja`', '`RomskeOrganizacijeSeeder`', '`KalendarProslaGodinaSeeder`', '`composer test`', '`php artisan serve`'] as $komanda) {
            $this->assertStringContainsString($komanda, $readme);
        }
    }

    // Nijedna vrednost iz .env, ni adresa administratorskog dela, ni ključ: samo imena podešavanja.
    #[Test]
    public function readme_nema_vrednosti_iz_env_a_ni_kljuceve_i_adrese(): void
    {
        $readme = $this->readme();
        $vrednosti = $this->vrednostiIzPrimera();

        // Dokaz da je merilo obišlo neprazan skup vrednosti iz primera (adresa sajta, port baze, oblast).
        $this->assertGreaterThanOrEqual(5, count($vrednosti));
        $this->assertContains('http://localhost:8000', $vrednosti);
        $this->assertContains('us-east-1', $vrednosti);

        foreach ($vrednosti as $vrednost) {
            $this->assertStringNotContainsString($vrednost, $readme, 'README ima vrednost iz .env: '.$vrednost);
        }

        $this->assertSame(0, preg_match('/\b[A-Z][A-Z0-9_]{2,}=\S/', $readme), 'README ima podešavanje sa vrednošću');
        $this->assertSame(0, preg_match('/sk-[A-Za-z0-9_-]{8,}/', $readme));
        $this->assertStringNotContainsString('http://', $readme);
        $this->assertStringNotContainsString('https://', $readme);
    }

    // Ogledalo: merilo vidi vrednost iz primera i podešavanje sa vrednošću kad ih ima.
    #[Test]
    public function merilo_vrednosti_prepoznaje_vrednost_iz_primera(): void
    {
        $this->assertContains('http://localhost:8000', $this->vrednostiIzPrimera());
        $this->assertSame(1, preg_match('/\b[A-Z][A-Z0-9_]{2,}=\S/', 'MODEL_OBRADE=claude'));
        $this->assertSame(0, preg_match('/\b[A-Z][A-Z0-9_]{2,}=\S/', '`MODEL_OBRADE`: Claude.'));
    }

    // README je na srpskom latinicom i jednostavan kao sajt: rečenice do petnaest reči i bez stranih reči sa spiska.
    #[Test]
    public function readme_je_na_srpskom_latinicom_i_jednostavan(): void
    {
        $readme = $this->readme();

        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $readme));
        $this->assertStringNotContainsString('About Laravel', $readme);
        $this->assertStringContainsString('Ovo je vežba. Sajt ne ide na produkciju.', $readme);
        $this->assertSame([], $this->razloziProze($readme));

        // Dokaz da je merilo obišlo prozu: ima dovoljno redova, a spisak stranih reči nije prazan.
        $this->assertGreaterThanOrEqual(20, count(array_filter(explode("\n", (string) preg_replace('/`[^`\n]*`/u', '', $readme)), fn (string $red) => preg_match('/\p{L}{4,}/u', $red) === 1)));
    }

    // Ogledalo: merilo prepoznaje dugu rečenicu i stranu reč izvan kodnih isečaka, a ne i u njima.
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function primeriProze(): array
    {
        return [
            'dugačka rečenica' => ['Ova rečenica ima mnogo više od petnaest reči jer u nju stalno ubacujem nove reči da bi bila predugačka za čitanje.', ['duga rečenica (21 reči)']],
            'strana reč' => ['Otvori filteri ovde.', ['strana reč: filteri']],
            'strana reč u kodu' => ['Pusti `uvoz:rss` ujutru.', []],
            'kratke rečenice' => ['Prva je kratka. Druga je takođe kratka.', []],
        ];
    }

    /** @param  list<string>  $razlozi */
    #[Test]
    #[DataProvider('primeriProze')]
    public function merilo_proze_prepoznaje_duge_recenice_i_strane_reci(string $readme, array $razlozi): void
    {
        $this->assertSame($razlozi, $this->razloziProze($readme));
    }

    // Folder Dokumentacija/ je van gita (README to tvrdi), pa .gitignore mora to da kaže.
    #[Test]
    public function dokumentacija_je_van_gita_kako_readme_tvrdi(): void
    {
        $this->assertStringContainsString('Folder `Dokumentacija/` nije u gitu.', $this->readme());
        $this->assertContains('/Dokumentacija/', array_map('trim', file(base_path('.gitignore')) ?: []));
        $this->assertFileExists(base_path('CLAUDE.md'));
        $this->assertDirectoryExists(base_path('.ai/rules'));
    }
}

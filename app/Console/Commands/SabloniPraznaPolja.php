<?php

namespace App\Console\Commands;

use App\Models\Sablon;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

// Jednokratno: seeder zadaje pravila praznih polja (paket 70/T10) samo šablonima koje sam pravi, pa ih ovde dobijaju i oni koji već postoje (PITANJA-70, 1).
class SabloniPraznaPolja extends Command
{
    protected $signature = 'sabloni:prazna-polja';

    protected $description = 'Upisuje pravila praznih polja (CV, motivaciono pismo, moje predstavljanje) postojećim šablonima, samo gde to podešavanje još nije zadato';

    private const OPCIONO = 'red se ne vidi kad je prazno.';

    /** @var array<string, array<string, array{ako_prazno?: string, opciono?: true}>> slug šablona → ključ polja → pravilo, kako piše u VODICI-PRIMERI.md */
    private const PRAVILA = [
        'cv' => ['kursevi' => ['opciono' => true], 'preporuke' => ['ako_prazno' => 'Preporuke dostupne na zahtev']],
        'motivaciono-pismo' => ['kome' => ['ako_prazno' => 'Poštovani']],
        'moje-predstavljanje' => ['skola' => ['opciono' => true]],
    ];

    public function handle(): int
    {
        $upisano = 0;
        $preskoceno = 0;

        foreach (self::PRAVILA as $slug => $pravila) {
            $sablon = Sablon::query()->where('slug', $slug)->first();

            if ($sablon === null) {
                $this->line($slug.': preskočeno, šablona nema.');
                $preskoceno += count($pravila);

                continue;
            }

            [$polja, $redovi, $upisana] = $this->primeni((array) $sablon->polja, $slug, $pravila);

            if ($upisana > 0) {
                $sablon->polja = $polja;

                try {
                    $sablon->save();
                } catch (ValidationException $izuzetak) {
                    // Šablon koji pravilo objave ne pušta da se snimi ne sme da zaustavi ostale.
                    $this->line($slug.': nije upisano ('.collect($izuzetak->errors())->flatten()->first().').');
                    $preskoceno += count($pravila);

                    continue;
                }
            }

            foreach ($redovi as $red) {
                $this->line($red);
            }

            $upisano += $upisana;
            $preskoceno += count($pravila) - $upisana;
        }

        $this->line('Upisano: '.$upisano.'. Preskočeno: '.$preskoceno.'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $polja
     * @param  array<string, array{ako_prazno?: string, opciono?: true}>  $pravila
     * @return array{0: array<int, mixed>, 1: list<string>, 2: int} polja, redovi o svakom pravilu (redom) i koliko je pravila upisano
     */
    private function primeni(array $polja, string $slug, array $pravila): array
    {
        $redovi = [];
        $upisana = 0;

        foreach ($pravila as $kljuc => $pravilo) {
            $mesto = $slug.' · '.$kljuc.': ';
            $indeks = array_key_first(array_filter($polja, fn (mixed $polje) => is_array($polje) && ($polje['kljuc'] ?? null) === $kljuc));

            if ($indeks === null) {
                $redovi[] = $mesto.'preskočeno, polja nema.';

                continue;
            }

            $polje = $polja[$indeks];
            $imaRec = filled($polje['ako_prazno'] ?? null);
            $izostavljen = (bool) ($polje['opciono'] ?? false);

            // Forma ne dozvoljava reč i izostavljen red zajedno, pa se ono što nije zadato ne upisuje preko onog što jeste.
            $razlog = match (true) {
                isset($pravilo['ako_prazno']) && $imaRec, isset($pravilo['opciono']) && $izostavljen => 'već je podešeno.',
                isset($pravilo['ako_prazno']) && $izostavljen => 'red je podešen da se ne vidi.',
                isset($pravilo['opciono']) && $imaRec => 'polje već ima reč za prazno polje.',
                default => null,
            };

            if ($razlog !== null) {
                $redovi[] = $mesto.'preskočeno, '.$razlog;

                continue;
            }

            $polja[$indeks] = [...$polje, ...$pravilo];
            $upisana++;
            $redovi[] = $mesto.(isset($pravilo['ako_prazno']) ? 'kad je prazno, piše „'.$pravilo['ako_prazno'].'".' : self::OPCIONO);
        }

        return [$polja, $redovi, $upisana];
    }
}

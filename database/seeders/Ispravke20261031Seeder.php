<?php

namespace Database\Seeders;

use App\Enums\Grupa;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\Prikaz;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

// Paket 94 (Ivan, 30.10.2026.: „zašto ja, zašto ne AI"): jednokratne ispravke iz pregleda. Prilika se traži po slugu, a polje se menja samo ako je u bazi vrednost koja se očekuje;
// inače se preskače uz razlog. Radnu bazu menja Ivan, kad pusti seeder.
class Ispravke20261031Seeder extends Seeder
{
    // Novi rok 15.11.2026. piše samo na ovoj vesti, pa link ide zajedno sa rokom: sa starom stranom bi ih prilike:provera sutra vratila u nacrt.
    public const VEST_O_PRODUZENJU = 'https://prosveta.gov.rs/vesti/produzen-rok-za-da-prijavu-za-studentske-kredite-studentske-stipendije-i-stipendija-za-nedostajuca-zanimanja/';

    public const NEDOSTAJUCA_ZANIMANJA = 'raspisan-konkurs-za-stipendije-za-nedostajuca-zanimanja-u-prosveti-za-skolsku-20262027-godine';

    public const STUDENTSKE_STIPENDIJE = 'raspisani-konkursi-za-dodelu-studentskih-stipendija-i-kredita-za-skolsku-20262027-godinu';

    private int $promenjeno = 0;

    private int $vecJe = 0;

    private int $preskoceno = 0;

    public function run(): void
    {
        $this->promenjeno = $this->vecJe = $this->preskoceno = 0;

        foreach (self::ispravkePrilika() as $ispravka) {
            $this->ispraviPriliku($ispravka);
        }

        // Posle prilika: rok iz ispravke čini priliku opet javnom, a veza kalendara sme samo na javnu.
        foreach (self::vezeKalendara() as $veza) {
            $this->povezi($veza['stavka'], $veza['prilika']);
        }

        $this->ispisi('Promenjeno '.$this->promenjeno.', već ispravljeno '.$this->vecJe.', preskočeno '.$this->preskoceno.'.');
    }

    /** @return list<array{slug: string, polje: string, ocekivano: mixed, novo: mixed}> polje je jedno; „ocekivano" je ono što mora da piše u bazi da bi se upisalo „novo" */
    public static function ispravkePrilika(): array
    {
        return [
            ['slug' => self::NEDOSTAJUCA_ZANIMANJA, 'polje' => 'rok', 'ocekivano' => '2026-11-02', 'novo' => '2026-11-15'],
            ['slug' => self::NEDOSTAJUCA_ZANIMANJA, 'polje' => 'link_izvora', 'ocekivano' => 'https://prosveta.gov.rs/vesti/raspisan-konkurs-za-stipendije-za-nedostajuca-zanimanja-u-prosveti/', 'novo' => self::VEST_O_PRODUZENJU],
            ['slug' => self::STUDENTSKE_STIPENDIJE, 'polje' => 'rok', 'ocekivano' => '2026-10-31', 'novo' => '2026-11-15'],
            ['slug' => self::STUDENTSKE_STIPENDIJE, 'polje' => 'link_izvora', 'ocekivano' => 'https://prosveta.gov.rs/vesti/raspisani-konkursi-za-dodelu-studentskih-stipendija-i-kredita-i-stipendije-za-nedostajuca-zanimanja-u-prosveti-za-skolsku-2026-2027-godinu/', 'novo' => self::VEST_O_PRODUZENJU],
            ['slug' => 'podrska-organizacijama-koje-rade-sa-mladima-i-zele-da-naprave-prvi-korak-u-erazmus-programu', 'polje' => 'grupe', 'ocekivano' => ['mladi', 'organizacije'], 'novo' => ['organizacije']],
            ['slug' => 'konkurs-za-prijem-studenata-visokoskolskih-ustanova-u-republici-srbiji-u-ustanove-za-smestaj-i-ishranu-studenata-za-skolsku-20262027-godinu', 'polje' => 'rok', 'ocekivano' => '2026-11-05', 'novo' => '2026-11-02'],
            ['slug' => 'javni-poziv-za-dodelu-opreme', 'polje' => 'mesto', 'ocekivano' => null, 'novo' => 'Mladenovac'],
            ['slug' => 'horizon-europe-excellence-hubs-2027-podrska-regionalnim-inovacionim-ekosistemima', 'polje' => 'status', 'ocekivano' => 'objavljeno', 'novo' => 'nacrt'],
        ];
    }

    /** @return list<array{stavka: string, prilika: string}> stavka se traži po nazivu, prilika po slugu */
    public static function vezeKalendara(): array
    {
        return [
            ['stavka' => 'Studentske stipendije i krediti', 'prilika' => self::STUDENTSKE_STIPENDIJE],
            ['stavka' => 'Stipendije za buduće nastavnike (nedostajuća zanimanja)', 'prilika' => self::NEDOSTAJUCA_ZANIMANJA],
        ];
    }

    /** @param  array{slug: string, polje: string, ocekivano: mixed, novo: mixed}  $ispravka */
    private function ispraviPriliku(array $ispravka): void
    {
        $prilika = Prilika::query()->where('slug', $ispravka['slug'])->first();

        if ($prilika === null) {
            $this->preskoci('prilike sa slugom „'.$ispravka['slug'].'“ nema u bazi.');

            return;
        }

        $polje = $ispravka['polje'];
        $trenutno = $this->vrednost($prilika, $polje);
        $ime = 'prilika '.$prilika->getKey().' („'.$prilika->naslov.'“), '.$this->imePolja($polje).':';

        if ($this->jednako($trenutno, $ispravka['novo'])) {
            $this->vecJe++;
            $this->ispisi('Već ispravljeno: '.$ime.' već je '.$this->opis($polje, $trenutno));
        } elseif ($this->jednako($trenutno, $ispravka['ocekivano'])) {
            $prilika->update([$polje => $ispravka['novo']]);
            $this->promenjeno++;
            $this->ispisi('Promenjeno: '.$ime.' bilo '.$this->opis($polje, $trenutno).', sada '.$this->opis($polje, $ispravka['novo']));
        } else {
            $this->preskoci($ime.' u bazi je '.$this->opis($polje, $trenutno).', a očekivano je '.$this->opis($polje, $ispravka['ocekivano']));
        }
    }

    private function povezi(string $nazivStavke, string $slugPrilike): void
    {
        $stavke = KalendarStavka::query()->where('naziv', $nazivStavke)->get();
        $ime = 'stavka kalendara „'.$nazivStavke.'“';

        if ($stavke->count() !== 1) {
            $this->preskoci($ime.': '.($stavke->isEmpty() ? 'nema je u bazi.' : 'u bazi ih je više sa istim nazivom.'));

            return;
        }

        $prilika = Prilika::query()->where('slug', $slugPrilike)->first();

        if ($prilika === null) {
            $this->preskoci($ime.': prilike sa slugom „'.$slugPrilike.'“ nema u bazi.');

            return;
        }

        $stavka = $stavke->first();
        $izabrana = $stavka->prilika_id === null ? null : (int) $stavka->prilika_id;

        if ($izabrana === (int) $prilika->getKey()) {
            $this->vecJe++;
            $this->ispisi('Već ispravljeno: '.$ime.' je povezana sa prilikom '.$prilika->getKey().'.');
        } elseif ($izabrana !== null) {
            $this->preskoci($ime.' je već povezana sa prilikom '.$izabrana.', a očekivano je da nije povezana.');
        } elseif (! KalendarStavka::prilikaJeJavna($prilika->getKey())) {
            $this->preskoci($ime.': prilika '.$prilika->getKey().' („'.$prilika->naslov.'“) nije javna, pa se ne povezuje.');
        } else {
            $stavka->update(['prilika_id' => $prilika->getKey()]);
            $this->promenjeno++;
            $this->ispisi('Promenjeno: '.$ime.' povezana sa prilikom '.$prilika->getKey().' („'.$prilika->naslov.'“).');
        }
    }

    // Vrednost polja onako kako je pišu ispravke: datum kao YYYY-MM-DD, status kao njegova vrednost, prazan tekst kao null.
    private function vrednost(Prilika $prilika, string $polje): mixed
    {
        return match ($polje) {
            'rok' => $prilika->rok?->toDateString(),
            'status' => $prilika->status->value,
            'grupe' => $prilika->grupe ?? [],
            default => blank($prilika->getAttribute($polje)) ? null : trim((string) $prilika->getAttribute($polje)),
        };
    }

    private function jednako(mixed $prva, mixed $druga): bool
    {
        if (is_array($prva) && is_array($druga)) {
            sort($prva);
            sort($druga);
        }

        return $prva === $druga;
    }

    private function imePolja(string $polje): string
    {
        return match ($polje) {
            'link_izvora' => 'link izvora',
            default => $polje,
        };
    }

    private function opis(string $polje, mixed $vrednost): string
    {
        return match (true) {
            $polje === 'rok' && is_string($vrednost) => Prikaz::datum(Carbon::parse($vrednost)),
            $polje === 'grupe' && is_array($vrednost) => $vrednost === [] ? 'bez grupa' : implode(', ', array_map(fn (string $grupa) => '„'.(Grupa::tryFrom($grupa)?->getLabel() ?? $grupa).'“', $vrednost)),
            $vrednost === null => 'prazno',
            default => '„'.$vrednost.'“',
        };
    }

    private function preskoci(string $razlog): void
    {
        $this->preskoceno++;
        $this->ispisi('Preskočeno: '.$razlog);
    }

    // Datum se piše sa tačkom na kraju (d.m.Y.), pa rečenica ne sme da doda još jednu.
    private function ispisi(string $tekst): void
    {
        $this->command->line(rtrim($tekst, '.').'.');
    }
}

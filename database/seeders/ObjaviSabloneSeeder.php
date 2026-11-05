<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use Illuminate\Database\Seeder;

// Paket 103/T2: Ivan je odobrio šest šablona (✅ u Dokumentacija/VODICI-PRIMERI.md), a VodiciPrimeriSeeder ih je uneo kao nacrt. Objavljuje se
// samo nacrt koji je isti kao u tom seederu; šta je već objavljeno ili menjano u adminu ostaje kako jeste. Radnu bazu menja Ivan, kad pusti seeder.
class ObjaviSabloneSeeder extends Seeder
{
    // Poređenje je strogo: i polje sačuvano u adminu bez izmene (forma dopiše prazne vrednosti) računa se kao menjano, pa ostaje nacrt.
    private const POREDI = ['naslov' => 'naslov', 'objasnjenje' => 'objašnjenje', 'polja' => 'polja', 'tekst' => 'tekst'];

    public function run(): void
    {
        $objavljeno = 0;
        $preskoceno = [];

        foreach (VodiciPrimeriSeeder::sabloni() as $podaci) {
            $sablon = Sablon::query()->where('slug', $podaci['slug'])->first();
            $razlog = $sablon === null ? 'nema ga u bazi' : self::razlogPreskoka($sablon, $podaci);

            if ($sablon !== null && $razlog === null) {
                $sablon->update(['status' => StatusObjave::Objavljeno]);
                $objavljeno++;
            } else {
                $preskoceno[] = $podaci['slug'].': '.$razlog;
            }
        }

        $this->command->line('Objavljeno '.$objavljeno.', preskočeno '.count($preskoceno).($preskoceno === [] ? '' : ' ('.implode('; ', $preskoceno).')').'.');
    }

    /** @param  array<string, mixed>  $podaci  šablon iz VodiciPrimeriSeeder::sabloni() */
    private static function razlogPreskoka(Sablon $sablon, array $podaci): ?string
    {
        if ($sablon->status !== StatusObjave::Nacrt) {
            return 'već je objavljen';
        }

        $razlike = array_values(array_filter(self::POREDI, fn (string $polje) => $sablon->getAttribute($polje) !== $podaci[$polje], ARRAY_FILTER_USE_KEY));

        return $razlike === [] ? null : 'razlikuje se od seedera: '.implode(', ', $razlike);
    }
}

<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use Illuminate\Database\Seeder;

// Dva opšta šablona (molba, zahtev): opšti oblik dopisa, bez ikakve tvrdnje o propisima. Ulaze kao nacrt, a slug koji već postoji se ne dira.
// Tekstove predlaže Code; Ivan pokreće seeder sam, pročita ih i objavi (PITANJA-46-52.md, paket 51).
class SabloniSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::sabloni() as $sablon) {
            if (Sablon::query()->where('slug', $sablon['slug'])->exists()) {
                continue;
            }

            Sablon::query()->create([...$sablon, 'status' => StatusObjave::Nacrt]);
        }
    }

    /** @return list<array{naslov: string, slug: string, objasnjenje: string, polja: list<array<string, mixed>>, tekst: string}> */
    public static function sabloni(): array
    {
        $podnosilac = [
            ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false],
            ['kljuc' => 'adresa', 'oznaka' => 'Adresa', 'objasnjenje' => 'Ulica, broj i mesto u kojem živiš.', 'dugo' => false],
            ['kljuc' => 'telefon', 'oznaka' => 'Telefon', 'objasnjenje' => 'Broj na kojem te mogu pozvati.', 'dugo' => false],
            ['kljuc' => 'primalac', 'oznaka' => 'Kome pišeš', 'objasnjenje' => 'Naziv ustanove ili ime osobe kojoj šalješ dopis.', 'dugo' => false],
        ];
        $kraj = [
            ['kljuc' => 'mesto', 'oznaka' => 'Mesto', 'objasnjenje' => 'Mesto u kojem pišeš dopis.', 'dugo' => false],
            ['kljuc' => 'datum', 'oznaka' => 'Datum', 'objasnjenje' => 'Dan kada pišeš dopis, npr. 10.10.2026.', 'dugo' => false],
        ];

        return [
            [
                'naslov' => 'Molba',
                'slug' => 'molba',
                'objasnjenje' => 'Za dopis u kojem nekoga lepo moliš za nešto: ustanovu, školu ili osobu.',
                'polja' => [
                    ...$podnosilac,
                    ['kljuc' => 'naslov', 'oznaka' => 'Naslov molbe', 'objasnjenje' => 'Kratko, npr. „Molba za upis deteta u školu“.', 'dugo' => false],
                    ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => 'Svojim rečima napiši šta moliš i zašto.', 'dugo' => true],
                    ...$kraj,
                ],
                'tekst' => "{ime}\n{adresa}\n{telefon}\n\n{primalac}\n\n{naslov}\n\nPoštovani,\n\n{tekst}\n\nUnapred hvala na razumevanju.\n\n{mesto}, {datum}\n\nS poštovanjem,\n{ime}",
            ],
            [
                'naslov' => 'Zahtev',
                'slug' => 'zahtev',
                'objasnjenje' => 'Za dopis u kojem od ustanove tražiš da ti nešto izda, uradi ili odgovori.',
                'polja' => [
                    ...$podnosilac,
                    ['kljuc' => 'predmet', 'oznaka' => 'Predmet', 'objasnjenje' => 'Kratko, npr. „Zahtev za izdavanje uverenja“.', 'dugo' => false],
                    ['kljuc' => 'tekst', 'oznaka' => 'Šta tražiš', 'objasnjenje' => 'Svojim rečima napiši šta tražiš i zašto ti treba.', 'dugo' => true],
                    ['kljuc' => 'prilozi', 'oznaka' => 'Šta prilažeš', 'objasnjenje' => 'Papiri koje šalješ uz dopis; ostavi prazno ako ništa ne prilažeš.', 'dugo' => true],
                    ...$kraj,
                ],
                'tekst' => "{ime}\n{adresa}\n{telefon}\n\n{primalac}\n\nPredmet: {predmet}\n\nPoštovani,\n\nobraćam Vam se sa zahtevom.\n\n{tekst}\n\nUz zahtev prilažem: {prilozi}\n\nMolim Vas da zahtev razmotrite i da mi odgovorite.\n\n{mesto}, {datum}\n\nS poštovanjem,\n{ime}",
            ],
        ];
    }
}

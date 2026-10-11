<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Database\Seeder;

// Primeri za vodiče i šabloni za „Napiši moj“ iz Dokumentacija/VODICI-PRIMERI.md, samo ono što je Ivan odobrio (paket 64). Tekstovi su prepisani doslovno.
// Ništa postojeće se ne menja: primeri se upisuju samo vodiču koji ih još nema, šabloni ulaze kao nacrt (slug koji postoji se ne dira), a vodič se vezuje
// za šablon samo kad još nema nijedan. Ivan pokreće seeder sam, pročita tekstove, i sam objavljuje šablone u adminu.
class VodiciPrimeriSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::primeri() as $slug => $primeri) {
            $vodic = Vodic::query()->where('slug', $slug)->first();

            if ($vodic !== null && $vodic->primeriZaPrikaz() === []) {
                $vodic->update(['primeri' => $primeri]);
            }
        }

        foreach (self::sabloni() as $podaci) {
            ['vodic' => $slugVodica] = $podaci;
            unset($podaci['vodic']);

            $sablon = Sablon::query()->where('slug', $podaci['slug'])->first()
                ?? Sablon::query()->create([...$podaci, 'status' => StatusObjave::Nacrt]);
            $vodic = Vodic::query()->where('slug', $slugVodica)->first();

            if ($vodic !== null && $vodic->sablon_id === null) {
                $vodic->update(['sablon_id' => $sablon->getKey()]);
            }
        }
    }

    /** @return array<string, list<array{naslov: string, tekst: string}>> slug vodiča → primeri, redom kojim se vide na strani */
    public static function primeri(): array
    {
        return [
            'kako-napisati-prvi-cv' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Ana Marković
Mobilni: 060 000 0000
E-pošta: ana.markovic@example.com
Niš

Cilj
Želim da radim u knjigovodstvu i da naučim da vodim poslovne knjige za malu firmu.

Praksa i volontiranje
mart 2025, dve nedelje – praksa, knjigovodstvena agencija, Niš
Svaki dan sam unosila račune u Excel tabelu i sa mentorkom proveravala iznose. Na kraju dana sam slagala papire u fascikle po mesecima.
2024 – danas – volonterka, Udruženje za podršku deci, Niš
Jednom nedeljno pomažem deci iz četvrtog razreda u učenju matematike.

Obrazovanje
2022–2026. Ekonomska škola, Niš
Smer: ekonomski tehničar

Kursevi
2025. Osnove rada u Excelu, kurs preko interneta, sa sertifikatom

Rad na računaru
Word – srednji nivo
Excel – srednji nivo
Internet i e-pošta

Jezici
srpski i romski – maternji
engleski – početni nivo

Preporuke
Jelena Stojanović, mentorka na praksi, knjigovodstvena agencija, Niš – 060 000 0001
TEKST],
            ],
            'kako-napisati-motivaciono-pismo' => [
                ['naslov' => 'Za stipendiju', 'tekst' => <<<'TEKST'
Poštovani,

prijavljujem se za stipendiju za studente prve godine. Moj cilj je da završim Ekonomski fakultet i da radim u knjigovodstvu u Nišu.

Ove godine sam upisala Ekonomski fakultet u Nišu. Pre toga sam završila Ekonomsku školu. U školi sam bila na praksi u knjigovodstvenoj agenciji. Tamo sam unosila račune u tabelu i videla kako izgleda pravi posao.

Ja sam prva u porodici koja studira. Roditelji ne mogu sami da plate stan i knjige u Nišu. Stipendija bi mi omogućila da učim, a ne da uz studije tražim posao.

Za pet godina želim da imam diplomu i da radim kao knjigovođa. Kasnije želim da pomažem malim firmama i udruženjima iz mog kraja da vode knjige kako treba.

Hvala vam što ste pročitali moje pismo.

S poštovanjem,
Ana Marković

Niš, 20. oktobar

Prilozi: CV, potvrda o upisu na fakultet
TEKST],
                ['naslov' => 'Za praksu', 'tekst' => <<<'TEKST'
Poštovani,

prijavljujem se za letnju praksu za studente u vašoj firmi. Oglas sam videla na sajtu fakulteta.

Studiram prvu godinu na Ekonomskom fakultetu u Nišu. U srednjoj školi sam bila dve nedelje na praksi u knjigovodstvenoj agenciji. Unosila sam račune u Excel tabelu i sa mentorkom proveravala iznose. Naučila sam da radim pažljivo i da pitam kad nešto ne znam.

Kod vas želim da vidim kako se vode knjige za različite firme. Praksu vidim kao prvi korak ka poslu u knjigovodstvu.

Rado ću doći na razgovor i reći vam više o sebi.

S poštovanjem,
Ana Marković

Niš, 10. maj

Prilozi: CV, potvrda da sam student
TEKST],
                ['naslov' => 'Za posao', 'tekst' => <<<'TEKST'
Poštovani,

prijavljujem se za posao konobara u vašem restoranu. Oglas sam video na internetu.

Završio sam srednju ugostiteljsku školu u Leskovcu, smer konobar. Prošlog leta sam tri meseca radio u kafani na Zlatiboru. Služio sam goste na terasi sa dvadeset stolova i naplaćivao na kasi. Naučio sam da radim brzo i kad je gužva.

Želim da radim kod vas jer volim posao sa ljudima. Želim i da naučim više o vinima i o tome kako se služe.

Rado ću doći na razgovor ili na probni dan.

S poštovanjem,
Marko Jovanović

Leskovac, 12. oktobar

Prilozi: CV, diploma srednje škole
TEKST],
                ['naslov' => 'Za program ili obuku', 'tekst' => <<<'TEKST'
Poštovani,

prijavljujem se za besplatnu obuku „Digitalne veštine za posao" vašeg udruženja. Poziv sam video na Fejsbuku.

Imam 24 godine i završio sam srednju mašinsku školu u Kragujevcu. Posle škole sam dve godine radio na gradilištu. Sada sam prijavljen u Nacionalnoj službi za zapošljavanje. Na gradilištu sam sam naučio da u Excelu vodim tabelu troškova za ekipu.

Na obuci želim da naučim rad na računaru kako treba i da pravim objave za male firme. Posle obuke želim da nađem posao u kancelariji ili da preko interneta radim za lokalne radnje.

Mogu da dolazim na sve časove. Rado ću doći na razgovor.

S poštovanjem,
Bojan Petrović

Kragujevac, 3. novembar

Prilozi: CV
TEKST],
            ],
            'kako-napisati-email-uz-prijavu' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Naslov: Prijava za posao konobara – Marko Jovanović

Poštovani,

prijavljujem se za posao konobara iz vašeg oglasa od 5. oktobra.

Završio sam srednju ugostiteljsku školu i imam tri meseca iskustva u kafani na Zlatiboru. Volim posao sa ljudima i mogu da radim u smenama.

U prilogu šaljem CV i motivaciono pismo, oba kao PDF. Rado ću doći na razgovor.

S poštovanjem,
Marko Jovanović
060 000 0000
marko.jovanovic@example.com
TEKST],
            ],
        ];
    }

    /** @return list<array{naslov: string, slug: string, vodic: string, objasnjenje: string, polja: list<array<string, mixed>>, tekst: string}> */
    public static function sabloni(): array
    {
        return [
            [
                'naslov' => 'Email uz prijavu',
                'slug' => 'email-uz-prijavu',
                'vodic' => 'kako-napisati-email-uz-prijavu',
                'objasnjenje' => 'Kratak mejl koji šalješ uz CV i ostale papire.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime.', 'dugo' => false],
                    ['kljuc' => 'posao', 'oznaka' => 'Za koji posao se prijavljuješ?', 'objasnjenje' => 'Upiši tačno kako piše u oglasu.', 'dugo' => false],
                    ['kljuc' => 'oglas', 'oznaka' => 'Kada i gde je objavljen oglas?', 'objasnjenje' => 'Može da ostane prazno.', 'dugo' => false],
                    ['kljuc' => 'ukratko', 'oznaka' => 'Ukratko: škola i iskustvo', 'objasnjenje' => 'Jedna do dve rečenice.', 'dugo' => false],
                    ['kljuc' => 'prilozi', 'oznaka' => 'Šta šalješ u prilogu?', 'objasnjenje' => 'Na primer „CV i motivaciono pismo".', 'dugo' => false],
                    ['kljuc' => 'telefon', 'oznaka' => 'Telefon', 'objasnjenje' => '', 'dugo' => false],
                    ['kljuc' => 'eposta', 'oznaka' => 'Tvoja e-pošta', 'objasnjenje' => 'Najbolje ime.prezime.', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
Naslov: Prijava za {posao} – {ime}

Poštovani,

prijavljujem se za {posao}. Oglas: {oglas}.

{ukratko}

U prilogu šaljem: {prilozi}. Rado ću doći na razgovor.

S poštovanjem,
{ime}
{telefon}
{eposta}
TEKST,
            ],
            [
                'naslov' => 'Motivaciono pismo',
                'slug' => 'motivaciono-pismo',
                'vodic' => 'kako-napisati-motivaciono-pismo',
                'objasnjenje' => 'Pismo za stipendiju, praksu, posao, program ili obuku.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime; ide na kraj pisma.', 'dugo' => false],
                    ['kljuc' => 'prilika', 'oznaka' => 'Za šta se prijavljuješ, i gde?', 'objasnjenje' => 'Na primer „posao konobara u restoranu Lipa" ili „stipendija za studente".', 'dugo' => false],
                    ['kljuc' => 'oglas', 'oznaka' => 'Gde je objavljen oglas?', 'objasnjenje' => 'Može da ostane prazno.', 'dugo' => false],
                    ['kljuc' => 'kome', 'oznaka' => 'Kome se obraćaš?', 'objasnjenje' => 'Ako u oglasu piše ime osobe, upiši ga. Ako ne piše, ostavi prazno, pa piše „Poštovani".', 'dugo' => false],
                    ['kljuc' => 'skola', 'oznaka' => 'Koju školu ili fakultet imaš, i koji smer?', 'objasnjenje' => '', 'dugo' => false],
                    ['kljuc' => 'iskustvo', 'oznaka' => 'Gde imaš iskustva i šta je tamo bio tvoj posao?', 'objasnjenje' => 'Posao, praksa, volontiranje ili rad u školi. Opiši jedan običan dan.', 'dugo' => true],
                    ['kljuc' => 'zasto', 'oznaka' => 'Zašto želiš baš ovo?', 'objasnjenje' => 'Šta hoćeš da naučiš ili postigneš.', 'dugo' => true],
                    ['kljuc' => 'kraj', 'oznaka' => 'Mesto, datum i šta šalješ uz pismo', 'objasnjenje' => 'Na primer „Leskovac, 12. oktobar, CV i diploma".', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
{kome},

prijavljujem se za {prilika}. Oglas: {oglas}.

Moja škola: {skola}.

{iskustvo}

{zasto}

Rado ću doći na razgovor.

S poštovanjem,
{ime}

{kraj}
TEKST,
            ],
            [
                'naslov' => 'CV',
                'slug' => 'cv',
                'vodic' => 'kako-napisati-prvi-cv',
                'objasnjenje' => 'Prvi CV, i kad nemaš radno iskustvo.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime.', 'dugo' => false],
                    ['kljuc' => 'telefon', 'oznaka' => 'Mobilni telefon', 'objasnjenje' => '', 'dugo' => false],
                    ['kljuc' => 'eposta', 'oznaka' => 'E-pošta', 'objasnjenje' => 'Najbolje ime.prezime.', 'dugo' => false],
                    ['kljuc' => 'mesto', 'oznaka' => 'Mesto', 'objasnjenje' => 'Grad ili mesto u kojem živiš.', 'dugo' => false],
                    ['kljuc' => 'cilj', 'oznaka' => 'Šta želiš da radiš?', 'objasnjenje' => 'Jedna rečenica, na primer „Želim da radim u knjigovodstvu."', 'dugo' => false],
                    ['kljuc' => 'iskustvo', 'oznaka' => 'Praksa, posao ili volontiranje', 'objasnjenje' => 'Kada, gde i šta je bio tvoj posao. Opiši jedan običan dan. Najnovije prvo.', 'dugo' => true],
                    ['kljuc' => 'obrazovanje', 'oznaka' => 'Škola i fakultet', 'objasnjenje' => 'Godine, naziv škole, mesto i smer.', 'dugo' => true],
                    ['kljuc' => 'kursevi', 'oznaka' => 'Kursevi', 'objasnjenje' => 'Naziv, godina, ima li sertifikat. Ako ih nemaš, ostavi prazno.', 'dugo' => true],
                    ['kljuc' => 'racunar', 'oznaka' => 'Rad na računaru', 'objasnjenje' => 'Programi i nivo: početni, srednji, napredni.', 'dugo' => false],
                    ['kljuc' => 'jezici', 'oznaka' => 'Jezici', 'objasnjenje' => 'Koji jezici i koliko dobro.', 'dugo' => false],
                    ['kljuc' => 'preporuke', 'oznaka' => 'Ko može da potvrdi tvoje iskustvo?', 'objasnjenje' => 'Ime, gde radi i telefon, samo ako se ta osoba složila. Ako ostaviš prazno, piše „Preporuke dostupne na zahtev".', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
{ime}
Mobilni: {telefon}
E-pošta: {eposta}
{mesto}

Cilj
{cilj}

Praksa i iskustvo
{iskustvo}

Obrazovanje
{obrazovanje}

Kursevi
{kursevi}

Rad na računaru
{racunar}

Jezici
{jezici}

Preporuke
{preporuke}
TEKST,
            ],
        ];
    }
}

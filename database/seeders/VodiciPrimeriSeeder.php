<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Database\Seeder;

// Primeri za vodiče i šabloni za „Napiši moj“ iz Dokumentacija/VODICI-PRIMERI.md, samo ono što je Ivan odobrio (paketi 64 i 69). Tekstovi su prepisani doslovno.
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
            'kako-traziti-pismo-preporuke' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Naslov: Molba za pismo preporuke – Ana Marković

Poštovana profesorko Petrović,

prijavljujem se za stipendiju za učenike srednjih škola. Uz prijavu je potrebno pismo preporuke, pa vas molim da mi ga vi napišete.

Dve godine ste mi bili razredna i znate kako radim. Ako se slažete, u preporuci bi bilo dobro da piše:
– da redovno dolazim na nastavu i imam odličan uspeh;
– da sam pomagala drugarima iz odeljenja da nauče matematiku;
– da sam učestvovala u školskoj akciji prikupljanja knjiga.

Preporuka mi treba do 25. novembra. Prilažem je ja, uz svoju prijavu, pa mi je možete dati u školi ili poslati mejlom. U prilogu šaljem uslove konkursa i svoj CV.

Hvala vam unapred.

Srdačan pozdrav,
Ana Marković
060 000 0000
TEKST],
            ],
            'kako-se-prijaviti-za-stipendiju' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Moj spisak za učeničku stipendiju

Konkurs: Ministarstvo prosvete, stipendije za učenike srednjih škola
Rok: od 1. do 30. septembra
Prijava: na portalu kreditistipendije.prosveta.gov.rs, a papiri u mojoj školi

Pre prijave:
– javila sam se Nacionalnom savetu romske nacionalne manjine (ili Kancelariji za inkluziju Roma) radi verifikacije
– pročitala sam uslove: za učenike Rome ne važi najmanji prosek 3,50

Dokumenti:
– prijava sa portala
– uverenje o prihodima porodice za januar–jun
– lična karta roditelja: odštampani podaci ili fotokopija
– uverenje iz škole o razredu i uspehu
– potvrde nisu starije od 90 dana

Poslato na portalu: ________
Papiri predati u školi: ________
Potvrdu o predaji sačuvala: da / ne
TEKST],
            ],
            'sta-ako-ti-fali-dokument' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Naslov: Pitanje o dokumentu za konkurs – Ana Marković

Poštovani,

prijavljujem se na konkurs za učeničke stipendije i u uslovima piše da treba uverenje o državljanstvu. Nemam ga, a rok za prijavu je za deset dana.

Da li uverenje možete da pribavite vi, iz službene evidencije? Ako ne možete, da li mogu da ga dostavim posle roka, uz potvrdu da sam ga zatražila?

Hvala,
Ana Marković
060 000 0000
TEKST],
            ],
            'kako-se-pripremiti-za-razgovor' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Moja priprema za razgovor

Posao: konobar u restoranu „Lipa", Leskovac
Kada: utorak, 14. oktobar, u 10 sati
Ponesem: CV, ličnu kartu, olovku
Obučem: čistu i opeglanu košulju, pantalone i cipele (ne patike)

Moje predstavljanje (oko 30 sekundi):
„Zovem se Marko Jovanović. Završio sam srednju ugostiteljsku školu u Leskovcu. Tri meseca sam radio u kafani na Zlatiboru: služio sam goste i naplaćivao. Volim posao sa ljudima i mogu da radim u smenama."

Šta iz oglasa sam već radio:
– služio goste i naplaćivao
– radio u smenama

Pitanja koja ću postaviti:
– Šta bih radio prvih nedelja?
– Kakvo je radno vreme?
– Kada mogu da očekujem odgovor?

Ako ne mogu da dođem: javljam se telefonom dan ranije.
TEKST],
            ],
            'kako-razumeti-uslove-konkursa' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Iz konkursa: „Rok za prijavljivanje na konkurs je od 1. do 30. septembra 2026. godine."
Znači: prijava posle 30. septembra ne važi.

Iz konkursa: „Potvrde ne mogu biti starije od devedeset (90) dana."
Znači: potvrda mora da bude izdata u poslednja tri meseca. Starija ne važi.

Iz konkursa: „…najmanje vrlo dobar uspeh u učenju (3,50), izuzev … učenika pripadnika romske nacionalne manjine…"
Znači: drugi učenici iz osetljivih grupa moraju da imaju bar 3,50, a Romi ne moraju.
TEKST],
            ],
            'kako-poslati-prijavu-online' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Pre nego što klikneš „Pošalji":
– Ime, telefon i e-pošta su tačni, a e-poštu redovno proveravaš.
– Dodati su svi traženi dokumenti.
– Fajlovi su u traženom obliku, najčešće PDF.
– Nazivi fajlova su jasni, na primer Ana_Markovic_CV.pdf.
– Ako se prijava šalje preko eUprave, nalog si napravila ranije, ne poslednjeg dana.

Posle slanja:
– Sačuvaj potvrdu ili sliku ekrana.
– Proveri e-poštu, i folder za neželjenu poštu.
TEKST],
            ],
            'kako-se-prijaviti-za-posao-ili-praksu' => [
                ['naslov' => 'Primer', 'tekst' => <<<'TEKST'
Kad zoveš po oglasu:
„Dobar dan, zovem se Ana Marković. Zovem zbog oglasa za konobara koji sam videla na internetu. Da li je mesto još slobodno? Kako mogu da se prijavim?"

Zapiši posle razgovora:
– ime osobe sa kojom si pričala
– šta treba da pošalješ
– do kada

Za prakse i obuke preko Nacionalne službe za zapošljavanje:
– prijavi se na evidenciju lično, u filijali NSZ u svom mestu
– ponesi ličnu kartu i diplomu ili svedočanstvo (original)
TEKST],
            ],
            'kako-pripremiti-osnovni-portfolio' => [
                ['naslov' => 'Školski rad', 'tekst' => <<<'TEKST'
Rad: plakat za školsku priredbu, 2025.
Cilj: da pozove roditelje na priredbu.
Moj deo: sama sam napravila plakat na računaru.
Veštine: Canva, kratak i jasan tekst.
Rezultat: plakat je stajao u školi i u prodavnici u naselju.
Šta sam naučila: tekst treba da bude kratak i da se vidi izdaleka.
TEKST],
                ['naslov' => 'Rad rukama', 'tekst' => <<<'TEKST'
Rad: okrečen stan kod komšije, 2026. (slike pre i posle)
Cilj: komšija je hteo svetliji i čist stan.
Moj deo: sam sam okrečio dve sobe; ujak mi je pokazao kako.
Veštine: kitovanje, krečenje valjkom, zaštita poda i nameštaja.
Rezultat: komšija me je preporučio još dvojici.
Šta sam naučio: da prvo dobro pokrijem pod, pa tek onda krečim.
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
            [
                'naslov' => 'Molba za pismo preporuke',
                'slug' => 'molba-za-preporuku',
                'vodic' => 'kako-traziti-pismo-preporuke',
                'objasnjenje' => 'Kratka poruka nastavniku, profesoru ili poslodavcu kojeg moliš za preporuku.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime.', 'dugo' => false],
                    ['kljuc' => 'kome', 'oznaka' => 'Kome pišeš?', 'objasnjenje' => 'Kako se obraćaš toj osobi, na primer „Poštovana profesorko Petrović" ili „Poštovani gospodine Iliću".', 'dugo' => false],
                    ['kljuc' => 'prilika', 'oznaka' => 'Za šta se prijavljuješ?', 'objasnjenje' => 'Na primer „stipendija za učenike srednjih škola".', 'dugo' => false],
                    ['kljuc' => 'odakle', 'oznaka' => 'Odakle te ta osoba poznaje i koliko dugo?', 'objasnjenje' => 'Na primer „dve godine mi je bila razredna".', 'dugo' => false],
                    ['kljuc' => 'istakni', 'oznaka' => 'Šta ta osoba zna o tebi, a dobro je da piše u preporuci?', 'objasnjenje' => 'Dve ili tri stvari: uspeh, rad, pomoć drugima.', 'dugo' => true],
                    ['kljuc' => 'rok', 'oznaka' => 'Do kada ti treba preporuka?', 'objasnjenje' => 'Datum, na primer „25. novembra".', 'dugo' => false],
                    ['kljuc' => 'predaja', 'oznaka' => 'Ko predaje preporuku?', 'objasnjenje' => 'Piše u uslovima konkursa: ti uz svoju prijavu, ili ta osoba sama.', 'dugo' => false],
                    ['kljuc' => 'telefon', 'oznaka' => 'Broj telefona', 'objasnjenje' => '', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
Naslov: Molba za pismo preporuke – {ime}

{kome},

prijavljujem se za {prilika}. Uz prijavu je potrebno pismo preporuke, pa vas molim da mi ga vi napišete.

Poznajete me ovako: {odakle}. Ako se slažete, u preporuci bi bilo dobro da piše:
{istakni}

Preporuka mi treba do {rok}. Ko predaje preporuku: {predaja}. U prilogu šaljem uslove konkursa i svoj CV.

Hvala vam unapred.

Srdačan pozdrav,
{ime}
{telefon}
TEKST,
            ],
            [
                'naslov' => 'Pitanje o dokumentu',
                'slug' => 'pitanje-o-dokumentu',
                'vodic' => 'sta-ako-ti-fali-dokument',
                'objasnjenje' => 'Kratak mejl organizatoru konkursa kad ti fali dokument.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime.', 'dugo' => false],
                    ['kljuc' => 'konkurs', 'oznaka' => 'Na koji konkurs se prijavljuješ?', 'objasnjenje' => 'Na primer „konkurs za učeničke stipendije".', 'dugo' => false],
                    ['kljuc' => 'dokument', 'oznaka' => 'Koji dokument ti fali?', 'objasnjenje' => 'Tačno kako piše u uslovima, na primer „uverenje o državljanstvu".', 'dugo' => false],
                    ['kljuc' => 'rok', 'oznaka' => 'Do kada je rok za prijavu?', 'objasnjenje' => 'Datum, na primer „20. oktobra".', 'dugo' => false],
                    ['kljuc' => 'telefon', 'oznaka' => 'Broj telefona', 'objasnjenje' => '', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
Naslov: Pitanje o dokumentu za konkurs – {ime}

Poštovani,

prijavljujem se na {konkurs} i u uslovima piše da treba {dokument}. Nemam ga, a rok za prijavu je {rok}.

Da li taj dokument možete da pribavite vi, iz službene evidencije? Ako ne možete, da li mogu da ga dostavim posle roka, uz potvrdu da sam ga {zatražio/zatražila}?

Hvala,
{ime}
{telefon}
TEKST,
            ],
            [
                'naslov' => 'Moje predstavljanje',
                'slug' => 'moje-predstavljanje',
                'vodic' => 'kako-se-pripremiti-za-razgovor',
                'objasnjenje' => 'Kratko predstavljanje za razgovor, oko 30 sekundi.',
                'polja' => [
                    ['kljuc' => 'ime', 'oznaka' => 'Kako se zoveš?', 'objasnjenje' => 'Ime i prezime.', 'dugo' => false],
                    ['kljuc' => 'posao', 'oznaka' => 'Za koji posao ideš na razgovor?', 'objasnjenje' => 'Na primer „konobar u restoranu Lipa".', 'dugo' => false],
                    ['kljuc' => 'skola', 'oznaka' => 'Koju školu imaš?', 'objasnjenje' => 'Može da ostane prazno.', 'dugo' => false],
                    ['kljuc' => 'iskustvo', 'oznaka' => 'Gde imaš iskustva i šta je tamo bio tvoj posao?', 'objasnjenje' => 'Posao, praksa, volontiranje ili rad u porodici.', 'dugo' => true],
                    ['kljuc' => 'umem', 'oznaka' => 'Šta voliš ili dobro umeš da radiš?', 'objasnjenje' => 'Na primer „posao sa ljudima", „rad u smenama".', 'dugo' => false],
                ],
                'tekst' => <<<'TEKST'
Zovem se {ime}.
{skola}
{iskustvo}
{umem}
Želim da radim kao {posao}.
TEKST,
            ],
        ];
    }
}

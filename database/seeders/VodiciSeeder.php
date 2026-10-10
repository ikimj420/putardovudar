<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Models\Vodic;
use Illuminate\Database\Seeder;

// Sadržaj je iz Dokumentacija/NASLEDJE/podaci/GuideSeeder.php. Svi vodiči ulaze kao nacrt, a slug koji već postoji
// se ne dira, pa izmenjen vodič ostaje kakav je. Ivan pokreće seeder sam, pročita vodiče i objavi ih.
class VodiciSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::vodici() as $vodic) {
            if (Vodic::query()->where('slug', $vodic['slug'])->exists()) {
                continue;
            }

            Vodic::query()->create([...$vodic, 'status' => StatusObjave::Nacrt]);
        }
    }

    /** @return list<array{naslov: string, slug: string, kratak_opis: string, tekst: string, koraci: list<string>, beleska: string}> */
    public static function vodici(): array
    {
        return [
            [
                'naslov' => 'Kako napisati prvi CV',
                'slug' => 'kako-napisati-prvi-cv',
                'kratak_opis' => 'Šta staviti u CV kada nemaš radno iskustvo, kako opisati školu, veštine, volontiranje i kurseve.',
                'tekst' => 'Prvi CV treba da bude jasan, tačan i prilagođen poziciji. Na početku navedi ime, telefon i profesionalnu email adresu. Obrazovanje prikaži od najnovijeg ka starijem, a zatim izdvoji veštine, jezike, digitalne alate, kurseve, projekte i volontiranje. Kada nemaš radno iskustvo, opiši konkretne zadatke i rezultate iz škole, prakse ili rada u zajednici. Ne unosi osetljive lične podatke koji nisu traženi i pre slanja sačuvaj dokument kao PDF.',
                'koraci' => ['Napiši kontakt podatke.', 'Dodaj školu, kurseve i veštine.', 'Uključi volontiranje ili korisne aktivnosti.', 'Prilagodi redosled sadržaja konkretnom poslu ili praksi.', 'Proveri pravopis, kontakt podatke i PDF format pre slanja.'],
                'beleska' => 'Urednički vodič. Referentni izvori: Europass CV i NSZ materijali za aktivno traženje posla.',
            ],
            [
                'naslov' => 'Kako napisati motivaciono pismo',
                'slug' => 'kako-napisati-motivaciono-pismo',
                'kratak_opis' => 'Kako povezati svoje iskustvo sa konkursom, objasniti motivaciju i napisati kratko pismo bez opštih fraza.',
                'tekst' => 'Motivaciono pismo nije prepričan CV. U prvom delu jasno napiši za koju se priliku prijavljuješ i zašto ti je važna. Zatim izdvoji dva ili tri konkretna iskustva, veštine ili rezultata koji odgovaraju uslovima konkursa. Objasni šta želiš da naučiš ili doprineseš, bez preuveličavanja i podataka koje ne možeš da dokažeš. Pismo prilagodi svakoj prijavi, zadrži ga na približno jednoj strani i završi jasnim, profesionalnim zaključkom.',
                'koraci' => ['Izdvoji ključne uslove i cilj konkursa.', 'Poveži dva ili tri svoja konkretna iskustva sa tim uslovima.', 'Objasni motivaciju i očekivani doprinos.', 'Ukloni opšte fraze i proveri da li je svaka tvrdnja tačna.', 'Sačuvaj traženi format i proveri kome se pismo šalje.'],
                'beleska' => 'Urednički vodič zasnovan na Europass smernicama i uobičajenim zahtevima javnih konkursa.',
            ],
            [
                'naslov' => 'Kako se prijaviti za stipendiju',
                'slug' => 'kako-se-prijaviti-za-stipendiju',
                'kratak_opis' => 'Kako proveriti uslove, pripremiti dokumenta, napisati motivaciono pismo i poslati prijavu pre roka.',
                'tekst' => 'Pre rada na prijavi proveri da li pripadaš ciljnoj grupi, da li tvoj nivo obrazovanja odgovara uslovima i da li je poziv otvoren za kandidate iz Srbije. Zapiši rok, način prijave i spisak obaveznih dokumenata. Posebno proveri da li dokument mora biti overen, preveden ili izdat posle određenog datuma. Podatke uzimaj iz zvaničnog teksta konkursa, a ne samo iz objave koja ga prenosi.',
                'koraci' => ['Proveri kome je stipendija namenjena.', 'Zapiši rok i listu dokumenata.', 'Pripremi formular i motivaciono pismo.', 'Pošalji prijavu pre roka i sačuvaj potvrdu.'],
                'beleska' => 'Urednički vodič. Referentni izvori: Ministarstvo prosvete, Fond za mlade talente i Erasmus+ Srbija.',
            ],
            [
                'naslov' => 'Šta ako ti fali dokument?',
                'slug' => 'sta-ako-ti-fali-dokument',
                'kratak_opis' => 'Koraci koje možeš da uradiš ako nemaš potvrdu, formular, kopiju dokumenta ili ne znaš gde da se obratiš.',
                'tekst' => 'Ako dokument nedostaje, prvo u zvaničnom pozivu proveri da li je obavezan i u kom obliku se prihvata. Zatim utvrdi koja institucija ga izdaje, koliko traje postupak i da li se plaća taksa. Ako rok ne možeš da stigneš, kontaktiraj organizatora i pitaj da li postoji privremena potvrda ili naknadna dopuna; odgovor sačuvaj u pisanom obliku. Ne šalji lažan ili izmenjen dokument.',
                'koraci' => ['Proveri da li je dokument obavezan.', 'Nađi instituciju ili organizaciju koja može da pomogne.', 'Pitaj da li postoji zamenski dokaz.', 'Ne čekaj poslednji dan roka.'],
                'beleska' => 'Urednički vodič za bezbednu pripremu dokumentacije; ne zamenjuje zvanično uputstvo konkretnog konkursa.',
            ],
            [
                'naslov' => 'Kako se pripremiti za razgovor',
                'slug' => 'kako-se-pripremiti-za-razgovor',
                'kratak_opis' => 'Najčešća pitanja, kako predstaviti sebe, šta pitati poslodavca i kako se ponašati na razgovoru.',
                'tekst' => 'Za razgovor prouči opis posla, organizaciju i uslove koje si naveo u prijavi. Pripremi kratko predstavljanje i nekoliko primera situacija u kojima si rešio problem, sarađivao sa drugima ili naučio novu veštinu. Ako nemaš formalno iskustvo, koristi primere iz škole, volontiranja, porodičnog posla ili projekta. Pripremi pitanja o zadacima, obuci, radnom vremenu i narednim koracima, ali ne izmišljaj iskustvo.',
                'koraci' => ['Pročitaj opis posla ili prakse.', 'Pripremi kratko predstavljanje.', 'Vežbaj odgovore na osnovna pitanja.', 'Spremi jedno ili dva pitanja za poslodavca.'],
                'beleska' => 'Urednički vodič. Referentni izvor: NSZ materijali za pripremu razgovora i aktivno traženje posla.',
            ],
            [
                'naslov' => 'Kako razumeti uslove konkursa',
                'slug' => 'kako-razumeti-uslove-konkursa',
                'kratak_opis' => 'Objašnjenje pojmova kao što su ciljna grupa, rok, prihvatljivi troškovi, dokaz i zvanični izvor.',
                'tekst' => 'Konkurs čitaj redom: ko raspisuje poziv, ko ima pravo da se prijavi, šta se podržava, koji troškovi ili aktivnosti su prihvatljivi, koji je rok i kako se prijava podnosi. Razlikuj direktnog podnosioca prijave od krajnjih korisnika projekta. Ako objava sadrži više rokova ili obrazaca, proveri koji se odnosi na tvoju kategoriju. Sve nejasnoće zapiši i proveri preko zvaničnog kontakta pre slanja.',
                'koraci' => ['Proveri rok.', 'Proveri kome je konkurs namenjen.', 'Proveri potrebna dokumenta.', 'Proveri zvanični link ili kontakt organizatora.'],
                'beleska' => 'Urednički vodič za čitanje javnih poziva i konkursa; konkretan zvanični tekst uvek ima prednost.',
            ],
            [
                'naslov' => 'Kako poslati prijavu online',
                'slug' => 'kako-poslati-prijavu-online',
                'kratak_opis' => 'Kako popuniti formu, dodati dokumenta, proveriti email i sačuvati potvrdu da je prijava poslata.',
                'tekst' => 'Pre otvaranja formulara pripremi tekstove i dokumenta u traženim formatima. Proveri ograničenje veličine fajlova, naziv dokumenata i da li sistem traži nalog ili verifikaciju email adrese. Ne ostavljaj slanje za poslednje minute. Posle slanja sačuvaj potvrdu, broj prijave ili snimak ekrana i proveri da li je stigao automatski email.',
                'koraci' => ['Popuni sva obavezna polja.', 'Dodaj dokumenta u traženom formatu.', 'Proveri email adresu.', 'Sačuvaj potvrdu o slanju.'],
                'beleska' => 'Urednički vodič za tehničku proveru online prijave.',
            ],
            [
                'naslov' => 'Kako se prijaviti za posao ili praksu',
                'slug' => 'kako-se-prijaviti-za-posao-ili-praksu',
                'kratak_opis' => 'Kako pročitati oglas, prilagoditi CV, pripremiti tražena dokumenta i poslati urednu prijavu.',
                'tekst' => 'Počni od celog teksta oglasa i proveri opis zadataka, obavezne uslove, lokaciju, radno vreme i način prijave. Izdvoji iskustva i veštine koje zaista odgovaraju oglasu, pa prema njima prilagodi CV i kratko propratno pismo. Pošalji samo dokumenta koja su tražena, koristi profesionalnu email adresu i sačuvaj oglas i potvrdu slanja. Ako nešto nije jasno, pitaj poslodavca pre isteka roka.',
                'koraci' => ['Pročitaj ceo oglas i izdvoji obavezne uslove.', 'Prilagodi CV poslu ili praksi.', 'Pripremi tražena dokumenta i kratak propratni tekst.', 'Pošalji prijavu na navedeni način i sačuvaj potvrdu.'],
                'beleska' => 'Urednički vodič za prijave na posao i praksu. Konkretan oglas i zvanični kontakt uvek imaju prednost.',
            ],
            [
                'naslov' => 'Kako napisati email uz prijavu',
                'slug' => 'kako-napisati-email-uz-prijavu',
                'kratak_opis' => 'Primer strukture kratkog i profesionalnog emaila kada šalješ CV, dokumenta ili prijavu.',
                'tekst' => 'Naslov emaila treba jasno da navede poziciju ili konkurs i tvoje ime ako je to traženo. U poruci se kratko predstavi, napiši za šta se prijavljuješ i navedi koje dokumente šalješ u prilogu. Ne prepisuj celo motivaciono pismo u email i ne koristi neformalne nadimke. Pre slanja proveri adresu primaoca, nazive i format priloga, kontakt podatke i pravopis.',
                'koraci' => ['Napiši jasan naslov poruke.', 'Predstavi se i navedi za šta se prijavljuješ.', 'Nabroj priloge koje šalješ.', 'Proveri adresu, priloge i potpis pre slanja.'],
                'beleska' => 'Urednički vodič za email prijave; ne sadrži izmišljene adrese niti univerzalni obrazac.',
            ],
            [
                'naslov' => 'Kako tražiti pismo preporuke',
                'slug' => 'kako-traziti-pismo-preporuke',
                'kratak_opis' => 'Koga zamoliti za preporuku, koje informacije poslati i koliko vremena ostaviti pre roka.',
                'tekst' => 'Za preporuku pitaj osobu koja poznaje tvoj rad, učenje, praksu ili volontiranje i može da navede konkretne primere. Javi se dovoljno rano i pošalji naziv prilike, rok, zvanične smernice, svoj CV i kratko objašnjenje zašto se prijavljuješ. Nemoj unapred pisati tuđu preporuku kao da ju je autor već odobrio. Pre slanja proveri da li se preporuka dostavlja direktno, u zatvorenoj koverti ili kao prilog tvojoj prijavi.',
                'koraci' => ['Izaberi osobu koja poznaje tvoj rad.', 'Pošalji joj uslove, rok i podatke o prilici.', 'Ostavi dovoljno vremena za pisanje.', 'Proveri propisan način dostavljanja preporuke.'],
                'beleska' => 'Urednički vodič za legitimno pribavljanje preporuke.',
            ],
            [
                'naslov' => 'Kako pripremiti osnovni portfolio',
                'slug' => 'kako-pripremiti-osnovni-portfolio',
                'kratak_opis' => 'Kako izabrati radove, kratko ih opisati i poslati portfolio koji odgovara prilici.',
                'tekst' => 'Portfolio treba da pokaže mali broj relevantnih radova, a ne sve što si ikada uradio. Za svaki primer ukratko napiši cilj, svoj doprinos, korišćene veštine i rezultat. Ako koristiš zajednički rad, jasno navedi koji deo je tvoj. Ukloni tuđe lične podatke i sadržaj koji nemaš pravo da objaviš. Portfolio može biti jedan pregledan PDF ili uredan javni link, u skladu sa zahtevom oglasa.',
                'koraci' => ['Izaberi tri do pet relevantnih radova.', 'Za svaki rad objasni svoj doprinos i rezultat.', 'Proveri dozvole i ukloni osetljive podatke.', 'Prilagodi redosled radova konkretnoj prilici.'],
                'beleska' => 'Urednički vodič za osnovni portfolio uz prijavu za posao, praksu ili program.',
            ],
        ];
    }
}

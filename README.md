# Putardo Vudar

Sajt pomaže Romima da nađu informacije: zapošljavanje, pisanje (molbe i prijave) i konkurse.
Podaci stižu sa interneta. Pomoćnik odgovara samo iz baze, uz izvor. Odgovor mu piše Claude.

Ovo je vežba. Sajt ne ide na produkciju.

## Šta ti treba

- PHP i Composer.
- MariaDB. Baza je ista i na računaru i u testovima.
- Ključ za Claude (Anthropic). Preko njega rade pomoćnik, „Napiši moj" i obrada novih prilika.
- Node, samo da se izgradi stil sajta.

## Kako se pokreće

1. Kopiraj `.env.example` u `.env` i upiši svoje vrednosti.
2. Pusti `composer setup`.
3. Pusti `php artisan serve`.

U `.env` stoje, između ostalog, ova podešavanja. Vrednosti su tvoje i ne pišu se u dokumente.

- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: baza.
- `ANTHROPIC_API_KEY`: ključ za Claude. Bez njega pomoćnik i obrada ne rade.
- `MODEL_OBRADE`: ko obrađuje nove prilike. Samo `claude`; svaka druga vrednost je greška.
- `JOOBLE_KLJUC`: ključ za poslove sa Jooble-a. Bez njega se taj izvor preskače.
- `ADMIN_PATH`: bez njega administratorski deo ne postoji.
- `PREDLOZI_SLANJA_U_MINUTI`, `POMOCNIK_UKUPNO_ZA_PITANJE`, `POMOCNIK_PITANJA_U_MINUTI`: ograničenja slanja.
- `POMOCNIK_PITANJA_DNEVNO`: koliko pitanja pomoćniku ceo sajt sme dnevno. Posle toga pomoćnik kaže da ne radi.

## Komande koje puštaš

Sve se puštaju sa `php artisan`, iz glavnog foldera.

- `uvoz:dnevno`: dnevni posao. Zove `uvoz:rss`, `uvoz:spiskovi`, `uvoz:jooble`, `uvoz:obradi`, `prilike:provera` i `prilike:arhiviraj-stare`, pa `kalendar:iz-prilika`. U rasporedu se pušta svakog dana u 06:00.
- `uvoz:obradi --ponovo`: ponovo obrađuje nacrte koje je obradilo starije pravilo.
- `prilike:provera`: proverava svaku javnu priliku prema strani izvora. Vraća u nacrt onu koja je zatvorena ili čiji rok ne piše na strani. Nov rok upisuje samo kad ga strana sama piše. Ako bi u nacrt otišlo previše prilika odjednom, ne upisuje ništa; granica je u `config/uvoz.php`.
- `prilike:arhiviraj-stare`: arhivira nacrte kojima je rok prošao pre više od 30 dana. Rok je u koloni ili, uz razlog „rok je prošao", u predlogu obrade.
- `kalendar:iz-prilika`: od objavljenih prilika kojima je rok prošao pravi nacrte stavki kalendara.
- `sesije:ocisti`: briše sesije starije od `SESSION_LIFETIME`. U rasporedu se pušta svakog sata.
- `sabloni:prazna-polja`: upisuje pravila praznih polja u šablone.

Seederi dodaju sadržaj, a ono što već postoji ne diraju. Izuzetak je `Ispravke20261031Seeder`. Puštaju se ovako: `db:seed --class=` i ime.

- `OrganizacijeSeeder`: organizacije iz starog projekta, kao nacrt.
- `RomskeOrganizacijeSeeder`: romske organizacije, objavljene.
- `KalendarProslaGodinaSeeder`: kalendar „šta je bilo otvoreno prošle godine".
- `VodiciSeeder`, `VodiciPrimeriSeeder` i `SabloniSeeder`: vodiči, primeri i šabloni, kao nacrt.
- `Ispravke20261031Seeder`: osam ispravki prilika i dve veze kalendara. Polje menja samo ako je u bazi očekivana vrednost, inače ispiše zašto preskače.

Ne puštaj `db:seed` bez imena. Taj poziv pravi probnog korisnika.

## Provera

- `composer test`: ceo prolaz testova. Testovi brišu i pune svoju bazu, koja ima drugo ime od radne.
- `composer provera`: formater, statička analiza i testovi, jedno za drugim.

## Gde su pravila

Pravila rada su u `CLAUDE.md` i u folderu `.ai/rules/`. Folder `Dokumentacija/` nije u gitu.

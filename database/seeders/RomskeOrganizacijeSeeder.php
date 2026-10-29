<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use Illuminate\Database\Seeder;

// Romske organizacije iz Dokumentacija/ORGANIZACIJE-NOVE.md, samo ono što je Ivan odobrio (✅, 13.10.2026.); tekstovi su prepisani doslovno. Za razliku od OrganizacijeSeeder,
// ulaze kao objavljene (Ivan je odobrio svaku, i hoće da se vide) i sa redosledom 1–6 (na vrhu strane). Slug koji već postoji se ne dira, ni polje „redosled". Ivan pokreće seeder sam.
class RomskeOrganizacijeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::organizacije() as $organizacija) {
            if (Organizacija::query()->where('slug', $organizacija['slug'])->doesntExist()) {
                Organizacija::query()->create([...$organizacija, 'status' => StatusObjave::Objavljeno]);
            }
        }
    }

    /** @return list<array{naziv: string, slug: string, vrsta: VrstaOrganizacije, kratak_opis: string, opis: string, mesto: string, online: bool, telefon?: string, eposta: string, sajt: string, usluge: list<string>, beleska: string, redosled: int}> */
    public static function organizacije(): array
    {
        return [
            [
                'naziv' => 'Opre Roma Srbija',
                'slug' => 'opre-roma-srbija',
                'vrsta' => VrstaOrganizacije::Udruzenje,
                'kratak_opis' => 'Građanski pokret Roma i njihovih prijatelja koji se bori za prava Roma i društvo bez diskriminacije.',
                'opis' => 'Opre Roma Srbija je građanski pokret osnovan 2019. godine. Čine ga Romi iz cele Srbije i ljudi koji nisu Romi, a žele dobro romskoj zajednici. Ne pripada nijednoj političkoj partiji i deo je evropske mreže romskih pokreta za učešće Roma u javnom životu. Moto: „Dobro za Rome, dobro za Srbiju".',
                'mesto' => 'Beograd, Majke Jevrosime 51',
                'online' => true,
                'eposta' => 'info@opreromasrbija.rs',
                'sajt' => 'https://opreromasrbija.rs/rs',
                'usluge' => ['Učlanjenje u pokret'],
                'beleska' => 'Provereno na zvaničnom sajtu https://opreromasrbija.rs/rs 13.10.2026.; adresa od Ivana.',
                'redosled' => 1,
            ],
            [
                'naziv' => 'ERIAC – Evropski romski institut za umetnost i kulturu',
                'slug' => 'eriac',
                'vrsta' => VrstaOrganizacije::Udruzenje,
                'kratak_opis' => 'Institut koji kroz umetnost, kulturu, istoriju i medije jača ponos Roma i smanjuje predrasude prema Romima.',
                'opis' => 'ERIAC je osnovan 2017. godine u Berlinu, kao zajednička inicijativa Saveta Evrope, Roma Foundation for Europe i romskih lidera. Kroz umetnost, kulturu, istoriju i medije jača samopouzdanje Roma i smanjuje predrasude prema njima. Podržava romske umetnike i objavljuje prilike, kao što su stipendije za romske filmske stvaraoce. ERIAC Serbia je registrovan u Srbiji od 2019. godine.',
                'mesto' => 'Beograd, Majke Jevrosime 51 (ERIAC Serbia); radnim danima od 9 do 17 h',
                'online' => true,
                'telefon' => '011 6906139',
                'eposta' => 'eriac.serbia@eriac.org',
                'sajt' => 'https://eriac.org/eriac-serbia/',
                'usluge' => ['Podrška romskim umetnicima', 'Pozivi i stipendije za umetnike', 'Članstvo'],
                'beleska' => 'Provereno na zvaničnom sajtu https://eriac.org/ 13.10.2026.',
                'redosled' => 2,
            ],
            [
                'naziv' => 'Roma Education Fund (REF)',
                'slug' => 'roma-education-fund',
                'vrsta' => VrstaOrganizacije::Fondacija,
                'kratak_opis' => 'Fondacija koja kroz obrazovanje pomaže Romima svih uzrasta: učenje, liderstvo, veštine i zapošljavanje.',
                'opis' => 'Roma Education Fund su 2005. godine osnovali Svetska banka i Fondacije za otvoreno društvo. Za dvadeset godina uložio je više od 120 miliona evra i pomogao više od 100.000 Roma u 16 zemalja. Ima programe za liderstvo, veštine i zapošljavanje, i Centar za inovacije. Ima i kancelariju za Srbiju (REF Serbia).',
                'mesto' => 'Beograd, Majke Jevrosime 51 (REF Serbia); radi i u još 15 zemalja',
                'online' => true,
                'eposta' => 'info@roma.education',
                'sajt' => 'https://roma.education/sr/',
                'usluge' => ['Obrazovna podrška', 'Liderstvo', 'Veštine i zapošljavanje'],
                'beleska' => 'Provereno na zvaničnom sajtu https://roma.education/ 13.10.2026.; adresa od Ivana.',
                'redosled' => 3,
            ],
            [
                'naziv' => 'REDI – Roma Entrepreneurship Development Initiative',
                'slug' => 'redi',
                'vrsta' => VrstaOrganizacije::Fondacija,
                'kratak_opis' => 'Mreža koja romskim preduzetnicima daje povoljne kredite, stručnu pomoć i podršku za razvoj posla.',
                'opis' => 'REDI je mreža neprofitnih organizacija u srednjoj i istočnoj Evropi. Romskim preduzetnicima i zajednicama daje pristup povoljnom finansiranju, stručnu pomoć i podršku za razvoj posla. U Srbiji traje program uz podršku Evropske unije (2024–2027) koji romskim preduzetnicima nudi pomoć oko posla, grantove i mentorstvo.',
                'mesto' => 'Beograd, Majke Jevrosime 51',
                'online' => true,
                'eposta' => 'info@redi-ngo.eu',
                'sajt' => 'https://www.redi-ngo.eu/',
                'usluge' => ['Povoljni krediti', 'Grantovi i mentorstvo za preduzetnike', 'Podrška za razvoj posla'],
                'beleska' => 'Provereno na zvaničnom sajtu https://www.redi-ngo.eu/ 13.10.2026.',
                'redosled' => 4,
            ],
            [
                'naziv' => 'Roma Foundation for Europe',
                'slug' => 'roma-foundation-for-europe',
                'vrsta' => VrstaOrganizacije::Fondacija,
                'kratak_opis' => 'Evropska fondacija koja gradi mrežu Roma i njihovih saveznika protiv rasizma, siromaštva i isključenosti iz politike.',
                'opis' => 'Roma Foundation for Europe veruje u znanje, snagu i otpornost Roma. Povezuje romske zajednice, kao birače i kao radnike, sa romskim zagovornicima i saveznicima. Radi na rešenjima u tri oblasti: novac, moć i vrednosti, odnosno ekonomija, politika i kultura. Kancelarije ima u Briselu, Berlinu, Bukureštu, Beogradu i Skoplju.',
                'mesto' => 'Brisel (sedište); kancelarija i u Beogradu',
                'online' => true,
                'eposta' => 'contact@romaforeurope.org',
                'sajt' => 'https://romaforeurope.org/',
                'usluge' => ['Novosti na mejl', 'Uključi se (Join)'],
                'beleska' => 'Provereno na zvaničnom sajtu https://romaforeurope.org/ 13.10.2026.',
                'redosled' => 5,
            ],
            [
                'naziv' => 'Roma for Democracy',
                'slug' => 'roma-for-democracy',
                'vrsta' => VrstaOrganizacije::Fondacija,
                'kratak_opis' => 'Evropska organizacija koju vode Romi i koja radi da Romi imaju svoj glas u politici — kao birači, kandidati i izabrani predstavnici.',
                'opis' => 'Romi su jedna od najvećih grupa birača u Evropi, a vrlo malo ih je u javnim funkcijama. Roma for Democracy pomaže da više Roma učestvuje na izborima i u javnom životu, ne navijajući ni za jednu stranku. Prati koliko Roma ima u parlamentima (Roma for Democracy Index) i beleži kada se Romima ometa glasanje.',
                'mesto' => 'Evropa (sajt je na engleskom)',
                'online' => true,
                'eposta' => 'hello@romafordemocracy.org',
                'sajt' => 'https://romafordemocracy.org/',
                'usluge' => ['Novosti na mejl', 'Uključi se (Act with us)'],
                'beleska' => 'Provereno na zvaničnom sajtu https://romafordemocracy.org/ 13.10.2026.',
                'redosled' => 6,
            ],
        ];
    }
}

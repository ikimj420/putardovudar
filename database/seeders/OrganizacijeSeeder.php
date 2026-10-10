<?php

namespace Database\Seeders;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use Illuminate\Database\Seeder;

// Sadržaj je iz Dokumentacija/NASLEDJE/podaci/OrganizationSeeder.php. Sve organizacije ulaze kao nacrt, a slug koji već
// postoji se ne dira. Ivan pokreće seeder sam, proveri adrese sajtova i objavi organizacije.
class OrganizacijeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::organizacije() as $organizacija) {
            if (Organizacija::query()->where('slug', $organizacija['slug'])->exists()) {
                continue;
            }

            Organizacija::query()->create([...$organizacija, 'status' => StatusObjave::Nacrt]);
        }
    }

    /** @return list<array{naziv: string, slug: string, vrsta: VrstaOrganizacije, kratak_opis: string, opis: string, mesto: string, online: bool, telefon: string, sajt: string, usluge: list<string>, beleska: string}> */
    public static function organizacije(): array
    {
        return [
            [
                'naziv' => 'Nacionalna služba za zapošljavanje',
                'slug' => 'nacionalna-sluzba-za-zaposljavanje',
                'vrsta' => VrstaOrganizacije::JavnaInstitucija,
                'kratak_opis' => 'Javna služba za oglase za posao, prijavu nezaposlenih, karijerno savetovanje, obuke i programe zapošljavanja širom Srbije.',
                'opis' => 'Nacionalna služba za zapošljavanje objavljuje oglase za posao i pruža informacije nezaposlenim licima i poslodavcima. Preko mreže filijala i online usluga dostupni su prijava na evidenciju, savetovanje, podrška pri traženju posla i informacije o programima zapošljavanja.',
                'mesto' => 'Mreža filijala širom Srbije i online usluge.',
                'online' => true,
                'telefon' => '0800/300-301',
                'sajt' => 'https://www.nsz.gov.rs/',
                'usluge' => ['Podrška za zapošljavanje', 'CV podrška', 'Savetovanje', 'Konkursi'],
                'beleska' => 'Provereno na zvaničnom sajtu NSZ: https://www.nsz.gov.rs/ i stranici kontakata.',
            ],
            [
                'naziv' => 'Ministarstvo prosvete - stipendije i krediti',
                'slug' => 'ministarstvo-prosvete-stipendije-i-krediti',
                'vrsta' => VrstaOrganizacije::JavnaInstitucija,
                'kratak_opis' => 'Zvanične informacije o učeničkim i studentskim stipendijama i kreditima, uključujući programe za romske učenike i studente.',
                'opis' => 'Ministarstvo prosvete objavljuje zvanične konkurse, uslove, obaveštenja i kontakte za učeničke i studentske stipendije i kredite u Srbiji. Pre prijave treba proveriti konkretnu stranicu konkursa i aktuelni rok.',
                'mesto' => 'Programi su namenjeni korisnicima širom Srbije; sedište Ministarstva je u Beogradu.',
                'online' => true,
                'telefon' => '011/3806-914',
                'sajt' => 'https://prosveta.gov.rs/skolski-i-studentski-zivot/stipendije/?pismo=lat',
                'usluge' => ['Pomoć oko stipendija', 'Obrazovna podrška', 'Konkursi', 'Kontakt sa institucijama'],
                'beleska' => 'Provereno na zvaničnoj stranici Ministarstva prosvete za stipendije 2026-07-21.',
            ],
            [
                'naziv' => 'Fondacija Tempus',
                'slug' => 'fondacija-tempus',
                'vrsta' => VrstaOrganizacije::Fondacija,
                'kratak_opis' => 'Informacije i podrška za Erasmus+, CEEPUS, obrazovanje, međunarodnu mobilnost, karijerno vođenje i projektne prijave.',
                'opis' => 'Fondacija Tempus sprovodi Erasmus+ u Srbiji i pruža informacije pojedincima, školama, fakultetima i organizacijama o obrazovanju, obukama, mobilnosti i projektnim mogućnostima. Info centar je dostupan u Beogradu i putem telefona i emaila.',
                'mesto' => 'Info centar: Terazije 39, I sprat, Beograd.',
                'online' => true,
                'telefon' => '+381 11 33 42 430',
                'sajt' => 'https://tempus.ac.rs/',
                'usluge' => ['Obrazovna podrška', 'Pomoć oko stipendija', 'Savetovanje', 'Podrška organizacijama', 'Konkursi'],
                'beleska' => 'Provereno na zvaničnom sajtu i kontakt stranici Fondacije Tempus 2026-07-21.',
            ],
            [
                'naziv' => 'Fond za mlade talente Republike Srbije',
                'slug' => 'fond-za-mlade-talente-republike-srbije',
                'vrsta' => VrstaOrganizacije::JavnaInstitucija,
                'kratak_opis' => 'Zvanični konkursi za stipendije i nagrade najboljim učenicima i studentima u Srbiji i studentima na studijama u inostranstvu.',
                'opis' => 'Fond za mlade talente Republike Srbije podržava razvoj mladih talenata kroz stipendije, nagrade i programe razvoja karijere. Uslovi i rokovi razlikuju se po konkursu i uvek se proveravaju na zvaničnom sajtu.',
                'mesto' => 'Konkursi su dostupni kandidatima širom Srbije; sedište Fonda je u Beogradu.',
                'online' => true,
                'telefon' => '011/311-07-85',
                'sajt' => 'https://fondzamladetalente.gov.rs/',
                'usluge' => ['Pomoć oko stipendija', 'Konkursi', 'Savetovanje', 'Obrazovna podrška'],
                'beleska' => 'Provereno na zvaničnoj stranici O Fondu i kontakt podacima 2026-07-21.',
            ],
            [
                'naziv' => 'Krovna organizacija mladih Srbije',
                'slug' => 'krovna-organizacija-mladih-srbije',
                'vrsta' => VrstaOrganizacije::Udruzenje,
                'kratak_opis' => 'Nacionalni savez organizacija mladih i za mlade koji povezuje članice, jača njihove kapacitete i zastupa interese mladih.',
                'opis' => 'KOMS je nezavisno predstavničko telo mladih u Srbiji. Povezuje organizacije članice sa institucijama i pruža aktivnosti jačanja kapaciteta organizacija i mladih koji u njima učestvuju.',
                'mesto' => 'Sekretarijat: Kralja Milutina 15, Beograd; aktivnosti su namenjene organizacijama i mladima širom Srbije.',
                'online' => true,
                'telefon' => '+381 11 407 6251',
                'sajt' => 'https://koms.rs/',
                'usluge' => ['Podrška organizacijama', 'Podrška zajednici', 'Konkursi', 'Savetovanje'],
                'beleska' => 'Provereno na zvaničnim stranicama O organizaciji i Kontakt KOMS-a 2026-07-21.',
            ],
            [
                'naziv' => 'Inicijativa A 11',
                'slug' => 'inicijativa-a-11',
                'vrsta' => VrstaOrganizacije::Inicijativa,
                'kratak_opis' => 'Pravna podrška i informacije o ekonomskim, socijalnim i drugim ljudskim pravima ugroženih grupa u Srbiji.',
                'opis' => 'Inicijativa A 11 promoviše i štiti ekonomska, socijalna i druga ljudska prava ugroženih grupa kroz pravnu podršku, javno zagovaranje, edukaciju i saradnju sa zajednicama.',
                'mesto' => 'Džordža Vašingtona 54/7, Beograd; podrška se odnosi na ugrožene grupe u Srbiji.',
                'online' => true,
                'telefon' => '011/3225-172',
                'sajt' => 'https://www.a11initiative.org/',
                'usluge' => ['Pravna pomoć', 'Podrška zajednici', 'Savetovanje', 'Kontakt sa institucijama'],
                'beleska' => 'Provereno na zvaničnim stranicama Vizija i misija, Aktivnosti i Kontakt A 11 2026-07-21.',
            ],
        ];
    }
}

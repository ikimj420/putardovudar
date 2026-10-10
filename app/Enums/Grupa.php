<?php

namespace App\Enums;

use App\Support\PoredjenjeTeksta;
use Filament\Support\Contracts\HasLabel;

// Kome je prilika namenjena. Redosled slučajeva je redosled na ekranu i u zapisu; jedno mesto za nazive.
enum Grupa: string implements HasLabel
{
    case Osnovci = 'osnovci';
    case Srednjoskolci = 'srednjoskolci';
    case Studenti = 'studenti';
    case MasterDoktorski = 'master-doktorski';
    case NezaposleniMladi = 'nezaposleni-mladi';
    case Povratnici = 'povratnici';
    case Organizacije = 'organizacije';
    case Romi = 'romi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Osnovci => 'Osnovci',
            self::Srednjoskolci => 'Srednjoškolci',
            self::Studenti => 'Studenti',
            self::MasterDoktorski => 'Master i doktorski studenti',
            self::NezaposleniMladi => 'Nezaposleni mladi',
            self::Povratnici => 'Povratnici iz inostranstva',
            self::Organizacije => 'Organizacije',
            self::Romi => 'Romi i Romkinje',
        };
    }

    // Citat potkrepljuje grupu samo ako sadrži reč te grupe (padeži koje rečnik navodi, sa dijakriticima ili bez, latinicom ili ćirilicom).
    // Rečnik je namerno uzak: „organizacije" su udruženja i organizacije civilnog društva, nikad firme; „roman" i „promocija" nisu Romi.
    public function potkrepljuje(string $citat): bool
    {
        return preg_match($this->uzorak(), PoredjenjeTeksta::normalizuj($citat)) === 1;
    }

    // Grupe čije reči stoje u tekstu, u redosledu enuma; šta nije u rečniku, nije grupa.
    /** @return list<self> */
    public static function izTeksta(string $tekst): array
    {
        return array_values(array_filter(self::cases(), fn (self $grupa) => $grupa->potkrepljuje($tekst)));
    }

    private const PREDLOZI = ['za', 'u', 'na', 'iz', 'od', 'do', 'po', 'sa', 'o', 'i', 'ili', 'a', 'uz', 'kod', 'pri', 'pod', 'nad', 'pre', 'oko', 'bez'];

    // Delovi teksta između reči grupa (normalizovani): ostaje ono što pitanje još traži, na primer „Opre Roma u zajednici" daje „opre" i
    // „zajednici". Delovi su posebni da ostanu jedna fraza kakva piše u tekstu; predlozi na krajevima ispadaju.
    /** @return list<string> */
    public static function bezReciGrupe(string $tekst): array
    {
        $ostatak = PoredjenjeTeksta::normalizuj($tekst);

        foreach (self::cases() as $grupa) {
            $ostatak = preg_replace($grupa->uzorak(), ' | ', $ostatak) ?? $ostatak;
        }

        return array_values(array_filter(array_map(self::bezPredlogaNaKrajevima(...), explode('|', $ostatak)), fn (string $deo) => $deo !== ''));
    }

    public static function bezPredlogaNaKrajevima(string $tekst): string
    {
        $reci = array_values(array_filter(explode(' ', $tekst), fn (string $rec) => $rec !== ''));

        while ($reci !== [] && in_array($reci[0], self::PREDLOZI, true)) {
            array_shift($reci);
        }

        while ($reci !== [] && in_array($reci[array_key_last($reci)], self::PREDLOZI, true)) {
            array_pop($reci);
        }

        return implode(' ', $reci);
    }

    // Uzorci rade nad normalizovanim tekstom (mala slova bez dijakritika, razmak umesto znakova), pa je granica reči \b.
    private function uzorak(): string
    {
        return match ($this) {
            self::Osnovci => '/\b(?:osnov(?:c|ac)[a-z]*|osnovnoskol[a-z]*|(?:ucenik|ucenic|dak(?:a|u|om)?\b|dac)[a-z]* (?:[a-z]+ ){0,3}osnovn[a-z]*)\b/',
            self::Srednjoskolci => '/\b(?:srednjoskol[a-z]*|gimnazijal(?:c|ac)[a-z]*|maturan(?:t|at)[a-z]*|(?:ucenik|ucenic|dak(?:a|u|om)?\b|dac)[a-z]* (?:[a-z]+ ){0,3}srednj[a-z]*)\b/',
            self::Studenti => '/\bstuden(?:t|at)[a-z]*\b/',
            self::MasterDoktorski => '/\b(?:master(?:a|u|om|i|e|ima|ant[a-z]*)?|magistar[a-z]*|magistr[a-z]*|doktorsk[a-z]*|doktoran(?:d|t|ad)[a-z]*|doktorat[a-z]*|postdiplomsk[a-z]*|postdiplomc[a-z]*|phd|msc)\b/',
            self::NezaposleniMladi => '/\b(?:nezaposlen[a-z]*|bez zaposlenja|bez posla|trazioc[a-z]* (?:posla|zaposlenja))\b/',
            self::Povratnici => '/\b(?:povratni[ck][a-z]*|repatrijac[a-z]*|readmisij[a-z]*|(?:vracen[a-z]*|povrata?k[a-z]*) (?:iz|u) (?:inostranstv[a-z]*|zemlj[a-z]*|srbij[a-z]*))\b/',
            self::Organizacije => '/\b(?:udruzenj[a-z]*|civiln[a-z]* (?:drustv[a-z]*|sektor[a-z]*)|nevladin[a-z]*|nvo|ocd|neprofitn[a-z]*|organizacij[a-z]* (?:koje|mladih|za mlade|zena|roma|osoba|gradjana|ljudskih))\b/',
            self::Romi => '/\brom(?:a|i|e|u|om|ima|kinj[a-z]*|sk[a-z]*)?\b/',
        };
    }

    /**
     * Vrednosti iz zapisa ili iz modela u grupe, u redosledu enuma, bez ponavljanja; šta nije grupa, ispada.
     *
     * @param  array<mixed>|null  $vrednosti
     * @return list<self>
     */
    public static function izVrednosti(?array $vrednosti): array
    {
        $trazene = (array) $vrednosti;

        return array_values(array_filter(self::cases(), fn (self $grupa) => in_array($grupa->value, $trazene, true)));
    }
}

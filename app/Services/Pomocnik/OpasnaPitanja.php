<?php

namespace App\Services\Pomocnik;

use App\Support\PoredjenjeTeksta;

// Odbija se namera (kako da JA lažiram, hakujem, prevarim), ne reč: pitanje žrtve i obične reči sa istim početkom prolaze.
// Stabla se ovde ne koriste, jer bi „hack" hvatao i „hackathon". Pismo i dijakritici se ne računaju, kao u ostalom poređenju.
// Pitanje se gleda rečenicu po rečenicu i deo posle zareza, jer normalizacija briše interpunkciju i spajala bi susedne rečenice.
final class OpasnaPitanja
{
    // Predlog nikad nije predmet: „namestim CV za konkurs" je uređivanje CV-a, a ne nameštanje konkursa.
    private const BEZ_PREDLOGA = '(?:(?!(?:za|u|na|o|od|do|po|sa|uz|iz|kod|pri|oko|bez|preko)\b)\w+\s+)';

    // Povratno „se" menja smisao („ako se ne varam" je „grešim", „može se hakovati" pita žrtva), pa takav oblik ne važi.
    private const BEZ_SE_PRE = '(?<!\bse )(?<!\bse ne )';

    private const BEZ_SE_POSLE = '(?!\s+se\b)';

    // Oblici namere: infinitiv, prvo lice (jednina i množina), drugo lice i zapovedni način („lažiraj mi dokument").
    // Trećeg lica, prošlog vremena, glagolske imenice i trpnog prideva nema namerno („prevario me je poslodavac",
    // „zaštita od hakovanja", „hakovan nalog" pitaju žrtve).
    private const GLAGOLI = [
        'lazir(?:ati|am|amo|as|aj|ajte)',
        'falsifik(?:ovati|ujem|ujemo|ujes|uj|ujte)',
        'hak(?:ovati|ujem|ujemo|ujes|uj|ujte)',
        'prevar(?:iti|im|imo|is)',
        'var(?:ati|am|amo|as|aj)',
    ];

    // Imenica sama ne znači nameru („Kako da prepoznam falsifikat?"); znači tek uz traženje da se dobije.
    private const LAZNO = '(?:lazn\w+\s+(?:dokument\w*|diplom\w*|potvrd\w*|prijav\w*)|falsifikat\w*|falsifikovan\w+\s+(?:dokument\w*|diplom\w*|potvrd\w*))';

    /** @return list<string> */
    private static function oblici(): array
    {
        $oblici = [];

        foreach (self::GLAGOLI as $glagol) {
            $oblici[] = '/'.self::BEZ_SE_PRE.'\b'.$glagol.'\b'.self::BEZ_SE_POSLE.'/';
        }

        return [
            ...$oblici,
            // „Namesti konkurs" i „zaobiđem uslove" traže i predmet, jer samo glagol ne govori ništa.
            '/'.self::BEZ_SE_PRE.'\b(?:namest(?:iti|im|imo|is|ati|am|amo|as|i)|prevari)\b'.self::BEZ_SE_POSLE.'\s+'.self::BEZ_PREDLOGA.'?konkurs\w*/',
            '/'.self::BEZ_SE_PRE.'\bzaobi(?:ci|dem|djem|demo|djemo|des|djes)\b'.self::BEZ_SE_POSLE.'\s+'.self::BEZ_PREDLOGA.'?uslov\w*/',
            // Traženje gotovog falsifikata.
            '/\b(?:kupim|kupiti|nabavim|nabaviti|napravim|napraviti|izradim|izraditi|nadem|nadjem|naci)\b(?:\s+\w+){0,3}?\s+'.self::LAZNO.'\b/',
            '/\b(?:treba|trebaju|hocu|zelim)\s+(?:mi\s+)?'.self::LAZNO.'\b/',
            // Engleski se ne obrađuje, ali stari spisak ga je imao; samo izrazi sa glagolom ili imenicom koja traži krivotvorenje.
            '/\bto (?:hack|cheat|forge)\b/',
            '/\bto commit fraud\b/',
            '/\bfake documents?\b/',
        ];
    }

    public function jeOpasno(string $pitanje): bool
    {
        foreach (preg_split('/[.,;:!?()\n\r–—]+/u', $pitanje) ?: [] as $deo) {
            $tekst = PoredjenjeTeksta::normalizuj($deo);

            foreach (self::oblici() as $oblik) {
                if (preg_match($oblik, $tekst) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}

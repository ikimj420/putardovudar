<?php

namespace App\Services\Pomocnik;

use App\Support\DatumiUTekstu;

// Tekst modela važi samo ako ne sadrži datum, broj, e-poštu ni vezu ka sajtu kojih nema u onome što je čovek upisao
// ili u stalnom tekstu šablona; inače ga menja šablon sklopljen sa odgovorima.
final class ProveraNapisanogTeksta
{
    public const NAJVISE_ZNAKOVA = 3000;

    private const EPOSTA = '/[\p{L}\d._%+-]+@[\p{L}\d.-]+\.\p{L}{2,}/u';

    private const DOMEN = '/(?<![\p{L}\d@.-])(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:rs|com|org|net|eu|info|edu|gov|me|io|co)(?:\/[^\s)\]»"„“]*)?(?![\p{L}\d])/iu';

    /** Vraća razlog odbijanja, ili null kad je tekst proveren. $dozvoljeno je sve što model sme da ponovi. */
    public function razlogOdbijanja(string $tekst, string $dozvoljeno): ?string
    {
        if (trim($tekst) === '') {
            return 'prazan tekst';
        }

        if (mb_strlen($tekst) > self::NAJVISE_ZNAKOVA) {
            return 'predug tekst';
        }

        // Neprazna mesta za polja bi ostala čoveku u pismu.
        if (str_contains($tekst, '{') || str_contains($tekst, '}')) {
            return 'viticaste zagrade u tekstu';
        }

        if (! $this->datumiSuDozvoljeni($tekst, $dozvoljeno)) {
            return 'datum kakvog nema u odgovorima';
        }

        if (! $this->nalaziSuDozvoljeni(self::EPOSTA, $tekst, $dozvoljeno)) {
            return 'e-pošta kakve nema u odgovorima';
        }

        if (! $this->nalaziSuDozvoljeni(self::DOMEN, $tekst, $dozvoljeno)) {
            return 'veza kakve nema u odgovorima';
        }

        if (! $this->brojeviSuDozvoljeni($tekst, $dozvoljeno)) {
            return 'broj kakvog nema u odgovorima';
        }

        return null;
    }

    private function datumiSuDozvoljeni(string $tekst, string $dozvoljeno): bool
    {
        $dozvoljeni = DatumiUTekstu::izvuci($dozvoljeno);

        foreach (DatumiUTekstu::izvuci($tekst) as $datum) {
            $postoji = false;

            foreach ($dozvoljeni as $mogao) {
                $postoji = $postoji || ($mogao['dan'] === $datum['dan'] && $mogao['mesec'] === $datum['mesec'] && ($datum['godina'] === null || $datum['godina'] === $mogao['godina']));
            }

            if (! $postoji) {
                return false;
            }
        }

        return true;
    }

    private function nalaziSuDozvoljeni(string $uzorak, string $tekst, string $dozvoljeno): bool
    {
        preg_match_all($uzorak, $tekst, $nadjeno);
        $mala = mb_strtolower($dozvoljeno);

        foreach ($nadjeno[0] as $nalaz) {
            if (! str_contains($mala, $this->normalizuj($nalaz))) {
                return false;
            }
        }

        return true;
    }

    // Svaki niz cifara u tekstu (deo telefona, broj kuće, godina, redni broj) mora da stoji kao niz cifara u dozvoljenom.
    private function brojeviSuDozvoljeni(string $tekst, string $dozvoljeno): bool
    {
        preg_match_all('/\d+/', $dozvoljeno, $dozvoljeni);
        preg_match_all('/\d+/', $tekst, $nadjeni);

        return array_diff($nadjeni[0], $dozvoljeni[0]) === [];
    }

    private function normalizuj(string $tekst): string
    {
        return rtrim(mb_strtolower((string) preg_replace('~^(?:https?://)?(?:www\.)?~i', '', trim($tekst))), '/.,;:!?');
    }
}

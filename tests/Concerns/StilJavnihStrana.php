<?php

namespace Tests\Concerns;

// Testovi koji čitaju javno.css ne smeju da se zavaraju: pravilo u komentaru ne važi, a pravilo u @media ne važi svuda.
trait StilJavnihStrana
{
    protected static function uklanjaKomentare(string $stil): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $stil);
    }

    // Ostaju samo pravila koja važe na svakoj širini: blokovi @media se izbacuju celi.
    protected static function uklanjaUpite(string $stil): string
    {
        $izlaz = '';
        $duzina = strlen($stil);

        for ($i = 0; $i < $duzina;) {
            if (substr($stil, $i, 6) !== '@media') {
                $izlaz .= $stil[$i++];

                continue;
            }

            for ($dubina = 0; $i < $duzina; $i++) {
                $dubina += $stil[$i] === '{' ? 1 : ($stil[$i] === '}' ? -1 : 0);

                if ($dubina === 0 && $stil[$i] === '}') {
                    $i++;

                    break;
                }
            }
        }

        return $izlaz;
    }

    protected function stilBezKomentara(): string
    {
        return self::uklanjaKomentare((string) file_get_contents(dirname(__DIR__, 2).'/public/css/javno.css'));
    }

    protected function stilBezUpita(): string
    {
        return self::uklanjaUpite($this->stilBezKomentara());
    }
}

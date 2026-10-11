<?php

namespace Tests\Unit;

use App\Services\RokSaInfostuda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 63/T4: datum isteka oglasa sa poslovi.infostud.com iz polja expirationDate, samo ako isti datum piše i u tekstu strane.
class RokSaInfostudaTest extends TestCase
{
    private const OGLAS = 'https://poslovi.infostud.com/posao/a/b/1';

    private const HTML = '<script>{"job":{"expirationDate":"25.10.2026.","id":1}}</script>';

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array{rok: string, citat: string}|null}> adresa, HTML, tekst, ishod */
    public static function strane(): array
    {
        $rok = ['rok' => '2026-10-25', 'citat' => '25.10.2026.'];

        return [
            'datum u polju i u tekstu' => [self::OGLAS, self::HTML, 'Magacioner Novi Sad 25.10.2026. ugovor', $rok],
            'http adresa' => ['http://poslovi.infostud.com/posao/a/b/1', self::HTML, 'Magacioner 25.10.2026. ugovor', $rok],
            'isti datum dvaput u strani' => [self::OGLAS, '{"expirationDate":"25.10.2026."} {"expirationDate":"25.10.2026."}', 'x 25.10.2026. y', $rok],
            'datum na kraju teksta' => [self::OGLAS, self::HTML, 'Magacioner 25.10.2026.', $rok],
            'drugi sajt' => ['https://a.test/oglas', self::HTML, 'Magacioner 25.10.2026. ugovor', null],
            'sajt sa sličnim imenom' => ['https://poslovi.infostud.com.test/a', self::HTML, 'Magacioner 25.10.2026. ugovor', null],
            'nema polja' => [self::OGLAS, '<script>{"job":{"id":1}}</script>', 'Magacioner 25.10.2026. ugovor', null],
            'datum nije u tekstu' => [self::OGLAS, self::HTML, 'Magacioner Novi Sad ugovor', null],
            'u tekstu je deo drugog broja' => [self::OGLAS, self::HTML, 'Magacioner 125.10.2026. ugovor', null],
            'u tekstu je nastavak broja' => [self::OGLAS, self::HTML, 'Magacioner 25.10.2026.5 ugovor', null],
            'dva različita datuma' => [self::OGLAS, '{"expirationDate":"25.10.2026."} {"expirationDate":"26.10.2026."}', 'x 25.10.2026. 26.10.2026.', null],
            'oblik Y-m-d' => [self::OGLAS, '{"expirationDate":"2026-10-25"}', 'x 2026-10-25 y', null],
            'oblik sa nastavkom iza godine' => [self::OGLAS, '{"expirationDate":"25.10.2026.7"}', 'x 25.10.2026.7 y', null],
            'oblik sa početkom ispred dana' => [self::OGLAS, '{"expirationDate":"1 25.10.2026."}', 'x 1 25.10.2026. y', null],
            'prazno polje' => [self::OGLAS, '{"expirationDate":""}', 'x y', null],
            'datum koji ne postoji' => [self::OGLAS, '{"expirationDate":"31.02.2026."}', 'x 31.02.2026. y', null],
            'prazna strana' => [self::OGLAS, '', '', null],
        ];
    }

    /** @param  array{rok: string, citat: string}|null  $ishod */
    #[Test]
    #[DataProvider('strane')]
    public function rok_se_uzima_samo_za_infostud_sa_jednim_datumom_koji_piše_i_u_tekstu(string $adresa, string $html, string $tekst, ?array $ishod): void
    {
        $this->assertSame($ishod, (new RokSaInfostuda)->izStrane($adresa, $html, $tekst));
    }

    // Jedno mesto u kodu za taj izvor: polje expirationDate čita samo RokSaInfostuda.
    #[Test]
    public function polje_expiration_date_cita_samo_klasa_za_infostud(): void
    {
        $nalazi = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', \FilesystemIterator::SKIP_DOTS)) as $fajl) {
            if (str_contains((string) file_get_contents($fajl->getPathname()), 'expirationDate')) {
                $nalazi[] = basename($fajl->getPathname());
            }
        }

        $this->assertSame(['RokSaInfostuda.php'], $nalazi);
    }
}

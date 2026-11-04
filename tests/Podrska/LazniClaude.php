<?php

namespace Tests\Podrska;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Lažni Claude API za testove: odgovor u obliku koji vraća Messages API i čitanje onoga što je aplikacija poslala (uputstvo je „system", pitanje je prva poruka).
// Poziv koji traži JSON objekat prepoznaje se po uputstvu („Vrati samo JSON objekat"), jer Claude nema poseban režim za JSON.
final class LazniClaude
{
    public const ADRESA = 'https://api.anthropic.com/v1/messages';

    // Lažan i bez vrednosti van testa; pravi ključ phpunit.xml prazni, pa test nikad ne vidi onaj iz okruženja.
    public const KLJUC = 'probni-kljuc-nije-pravi-123';

    public static function odgovor(string $tekst, int $ulaz = 100, int $izlaz = 20): PromiseInterface
    {
        return Http::response(['content' => [['type' => 'text', 'text' => $tekst]], 'usage' => ['input_tokens' => $ulaz, 'output_tokens' => $izlaz]]);
    }

    public static function jeJson(Request $zahtev): bool
    {
        return str_contains(self::uputstvo($zahtev), 'JSON');
    }

    public static function uputstvo(Request $zahtev): string
    {
        return (string) $zahtev['system'];
    }

    public static function poruka(Request $zahtev): string
    {
        return (string) $zahtev['messages'][0]['content'];
    }
}

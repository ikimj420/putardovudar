<?php

namespace Tests\Unit;

use App\Enums\VrstaPrilike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 102/T2 (Ivan „piši", 05.11.2026., PITANJA-101 2): izraz koji je sav od reči vrste kaže vrstu; izraz sa još jednom rečju je tema.
class IzrazSamoOdReciVrsteTest extends TestCase
{
    /** @return array<string, array{0: VrstaPrilike, 1: string}> */
    public static function samoReciVrste(): array
    {
        return [
            'kurs' => [VrstaPrilike::Obuka, 'kurs'],
            'Kursevi' => [VrstaPrilike::Obuka, 'Kursevi'],
            'kursa' => [VrstaPrilike::Obuka, 'kursa'],
            'radionica' => [VrstaPrilike::Obuka, 'radionica'],
            'radionice' => [VrstaPrilike::Obuka, 'radionice'],
            'trening' => [VrstaPrilike::Obuka, 'trening'],
            'obuka' => [VrstaPrilike::Obuka, 'obuka'],
            'kurs i radionica nisu dve teme' => [VrstaPrilike::Obuka, 'kurs radionica'],
            'ćirilica' => [VrstaPrilike::Obuka, 'курс'],
            'zaposlenje' => [VrstaPrilike::Posao, 'zaposlenje'],
            'radna mesta' => [VrstaPrilike::Posao, 'radna mesta'],
            'pripravnički' => [VrstaPrilike::Praksa, 'pripravnički'],
            'poziv' => [VrstaPrilike::Konkurs, 'poziv'],
        ];
    }

    #[Test]
    #[DataProvider('samoReciVrste')]
    public function izraz_od_reci_vrste_kaze_vrstu(VrstaPrilike $vrsta, string $izraz): void
    {
        $this->assertTrue($vrsta->jeSamoRecVrste($izraz));
    }

    /** @return array<string, array{0: VrstaPrilike, 1: string}> */
    public static function nijeSamoRecVrste(): array
    {
        return [
            'kurs uz drugu reč' => [VrstaPrilike::Obuka, 'kurs engleskog'],
            'online kurs' => [VrstaPrilike::Obuka, 'online kurs'],
            'reč koja počinje kao obuka' => [VrstaPrilike::Obuka, 'obuće'],
            'reč koja počinje kao kurs' => [VrstaPrilike::Obuka, 'kursor'],
            'prazno' => [VrstaPrilike::Obuka, ''],
            'samo interpunkcija' => [VrstaPrilike::Obuka, '?!'],
            'reč druge vrste' => [VrstaPrilike::Posao, 'kurs'],
            'radno bez mesta' => [VrstaPrilike::Posao, 'radno'],
            'drugo nema reči' => [VrstaPrilike::Drugo, 'drugo'],
        ];
    }

    // Ogledalo: izraz sa još jednom rečju, reč druge vrste i reč koja samo liči nisu vrsta, pa i dalje sužavaju pretragu.
    #[Test]
    #[DataProvider('nijeSamoRecVrste')]
    public function izraz_sa_drugom_recju_nije_vrsta(VrstaPrilike $vrsta, string $izraz): void
    {
        $this->assertFalse($vrsta->jeSamoRecVrste($izraz));
    }
}

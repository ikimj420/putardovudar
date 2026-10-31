<?php

namespace Tests\Unit;

use App\Enums\StatusObjave;
use App\Enums\StatusPrilike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 97/T2: jedno mesto za pitanje „da li je vrednost iz forme javna"; obe forme (kalendar, organizacije) pitaju ovde.
class StatusObjaveTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function vrednosti(): array
    {
        return [
            'enum objavljeno' => [StatusObjave::Objavljeno, true],
            'tekst objavljeno' => ['objavljeno', true],
            'enum nacrt' => [StatusObjave::Nacrt, false],
            'tekst nacrt' => ['nacrt', false],
            'velika slova nisu status' => ['Objavljeno', false],
            'razmak nije status' => ['objavljeno ', false],
            'prazan tekst' => ['', false],
            'null' => [null, false],
            'broj' => [1, false],
            'niz' => [['objavljeno'], false],
            'status prilike nije status objave' => [StatusPrilike::Objavljeno, false],
            'nepoznat tekst' => ['arhivirano', false],
        ];
    }

    #[Test]
    #[DataProvider('vrednosti')]
    public function vrednost_je_javna_samo_kad_je_objavljeno(mixed $vrednost, bool $javna): void
    {
        $this->assertSame($javna, StatusObjave::jeJavnaVrednost($vrednost));
    }

    // Ista odluka kao kod enuma: vrednost i instanca se ne razilaze ni za jedan slučaj.
    #[Test]
    public function vrednost_i_instanca_daju_isti_odgovor(): void
    {
        foreach (StatusObjave::cases() as $status) {
            $this->assertSame($status->jeJavno(), StatusObjave::jeJavnaVrednost($status->value), $status->value);
            $this->assertSame($status->jeJavno(), StatusObjave::jeJavnaVrednost($status), $status->value);
        }

        $this->assertCount(2, StatusObjave::cases());
    }
}

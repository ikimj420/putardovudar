<?php

namespace Database\Factories;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sablon>
 */
class SablonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naslov' => fake()->unique()->sentence(3),
            'objasnjenje' => fake()->sentence(),
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => 'Šta želiš da napišeš.', 'dugo' => true],
            ],
            'tekst' => "{ime}\n\n{tekst}",
            'status' => StatusObjave::Nacrt,
        ];
    }

    public function objavljen(): static
    {
        return $this->state(['status' => StatusObjave::Objavljeno]);
    }
}

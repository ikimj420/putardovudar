<?php

namespace Database\Factories;

use App\Enums\StatusObjave;
use App\Models\Vodic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vodic>
 */
class VodicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naslov' => fake()->unique()->sentence(4),
            'kratak_opis' => fake()->sentence(12),
            'tekst' => fake()->paragraphs(2, true),
            'koraci' => [fake()->sentence(4), fake()->sentence(4)],
            'beleska' => fake()->sentence(6),
            'status' => StatusObjave::Nacrt,
        ];
    }

    public function objavljen(): static
    {
        return $this->state(['status' => StatusObjave::Objavljeno]);
    }
}

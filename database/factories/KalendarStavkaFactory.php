<?php

namespace Database\Factories;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Models\KalendarStavka;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KalendarStavka>
 */
class KalendarStavkaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naziv' => fake()->unique()->sentence(4),
            'ko_daje' => fake()->company(),
            'grupe' => null,
            'otvoreno_od' => null,
            'otvoreno_do' => '2026-01-26',
            'link_izvora' => fake()->url(),
            'napomena' => null,
            'status' => StatusObjave::Nacrt,
        ];
    }

    public function objavljena(): static
    {
        return $this->state(['status' => StatusObjave::Objavljeno]);
    }

    public function zaGrupe(Grupa ...$grupe): static
    {
        return $this->state(['grupe' => array_map(fn (Grupa $grupa) => $grupa->value, $grupe)]);
    }
}

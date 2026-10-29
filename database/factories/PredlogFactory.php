<?php

namespace Database\Factories;

use App\Enums\StatusPredloga;
use App\Models\Predlog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Predlog>
 */
class PredlogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'link' => 'https://primer.rs/konkurs-'.fake()->unique()->numberBetween(1, 100000),
            'napomena' => fake()->sentence(6),
            'status' => StatusPredloga::Nov,
        ];
    }

    public function pregledan(): static
    {
        return $this->state(['status' => StatusPredloga::Pregledan]);
    }
}

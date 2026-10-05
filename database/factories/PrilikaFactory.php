<?php

namespace Database\Factories;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prilika>
 */
class PrilikaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naslov' => fake()->unique()->sentence(4),
            'vrsta' => fake()->randomElement(VrstaPrilike::cases()),
            'status' => StatusPrilike::Nacrt,
            'kratak_opis' => fake()->sentence(10),
            'opis' => fake()->paragraphs(2, true),
            'rok' => null,
            'rok_stalno_otvoren' => false,
            'mesto' => fake()->city(),
            'online' => false,
            'naziv_izvora' => fake()->company(),
            'link_izvora' => fake()->url(),
        ];
    }

    public function objavljena(): static
    {
        return $this->state(['status' => StatusPrilike::Objavljeno]);
    }

    public function arhivirana(): static
    {
        return $this->state(['status' => StatusPrilike::Arhivirano]);
    }

    public function saRokom(CarbonInterface $rok): static
    {
        return $this->state(['rok' => $rok->toDateString()]);
    }

    public function stalnoOtvorena(): static
    {
        return $this->state(['rok_stalno_otvoren' => true]);
    }
}

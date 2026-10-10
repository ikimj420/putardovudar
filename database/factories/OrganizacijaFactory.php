<?php

namespace Database\Factories;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organizacija>
 */
class OrganizacijaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'naziv' => fake()->unique()->company(),
            'vrsta' => fake()->randomElement(VrstaOrganizacije::cases()),
            'kratak_opis' => fake()->sentence(12),
            'opis' => fake()->paragraph(),
            'mesto' => fake()->city(),
            'online' => false,
            'telefon' => '011/123-456',
            'sajt' => 'https://primer.rs/',
            'usluge' => ['Savetovanje', 'Konkursi'],
            'beleska' => fake()->sentence(6),
            'status' => StatusObjave::Nacrt,
        ];
    }

    public function objavljena(): static
    {
        return $this->state(['status' => StatusObjave::Objavljeno]);
    }
}

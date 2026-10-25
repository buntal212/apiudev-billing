<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->company(), 'whatsapp_number' => fake()->numerify('628##########'), 'email' => fake()->safeEmail(), 'address' => fake()->address(), 'is_active' => true, 'notes' => fake()->optional()->sentence()];
    }
}

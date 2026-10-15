<?php

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper(fake()->unique()->bothify('APP-####')),
            'name' => fake()->company(),
            'description' => fake()->optional()->sentence(),
            'application_url' => fake()->optional()->url(),
            'contact_email' => fake()->optional()->safeEmail(),
            'is_active' => true,
        ];
    }
}

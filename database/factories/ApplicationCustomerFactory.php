<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationCustomer>
 */
class ApplicationCustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'external_customer_id' => fake()->unique()->bothify('CUSTOMER-####'),
            'name' => fake()->name(),
            'email' => fake()->optional()->safeEmail(),
            'phone_number' => fake()->optional()->phoneNumber(),
            'is_active' => true,
        ];
    }
}

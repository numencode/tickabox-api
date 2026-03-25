<?php

namespace Database\Factories;

use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'             => $this->faker->uuid(),
            'user_id'          => \App\Models\User::factory(),
            'title'            => $this->faker->sentence(),
            'is_completed'     => $this->faker->boolean(),
            'last_modified_at' => now(),
        ];
    }
}

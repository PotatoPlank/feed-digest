<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Digest>
 */
class DigestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feed_url' => $this->faker->url(),
            'name' => $this->faker->words(2, true),
            'timezone' => 'UTC',
            'filters' => [],
            'only_prior_to_today' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessSource>
 */
class BusinessSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 9_999_999_999);

        return [
            'business_id' => Business::factory(),
            'provider' => 'openstreetmap',
            'provider_id' => "node/{$id}",
            'is_primary' => true,
            'source_url' => "https://www.openstreetmap.org/node/{$id}",
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}

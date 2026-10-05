<?php

namespace Database\Factories;

use App\Enums\BusinessCategory;
use App\Models\Business;
use App\Models\BusinessSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'category' => BusinessCategory::Restaurant,
            'type' => 'restaurant',
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'latitude' => fake()->latitude(24.6, 24.8),
            'longitude' => fake()->longitude(46.6, 46.8),
            'first_discovered_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function withSource(string $provider = 'openstreetmap', ?string $providerId = null): static
    {
        return $this->has(BusinessSource::factory()->state([
            'provider' => $provider,
            'provider_id' => $providerId ?? 'node/'.fake()->unique()->numberBetween(1, 9_999_999_999),
            'is_primary' => true,
        ]), 'sources');
    }
}

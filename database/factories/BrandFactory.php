<?php

namespace Database\Factories;

use App\Enums\BrandScope;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'scope' => BrandScope::Unknown,
            'source' => 'nsi',
        ];
    }

    /**
     * A brand from the Name Suggestion Index, active worldwide.
     */
    public function international(?string $wikidataId = null): static
    {
        return $this->state(fn (): array => [
            'wikidata_id' => $wikidataId ?? 'Q'.fake()->unique()->numberBetween(1000, 9_999_999),
            'nsi_id' => fake()->unique()->slug(2),
            'scope' => BrandScope::International,
            'countries' => ['001'],
        ]);
    }

    /**
     * A brand registered in one country only.
     */
    public function national(string $countryCode = 'SA'): static
    {
        return $this->state(fn (): array => [
            'nsi_id' => fake()->unique()->slug(2),
            'scope' => BrandScope::National,
            'countries' => [$countryCode],
        ]);
    }
}

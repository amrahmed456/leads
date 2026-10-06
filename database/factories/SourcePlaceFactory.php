<?php

namespace Database\Factories;

use App\Enums\BusinessCategory;
use App\Models\AtpSpider;
use App\Models\SourcePlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourcePlace>
 */
class SourcePlaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'overture',
            'source_record_id' => fake()->unique()->uuid(),
            'dataset_type' => SourcePlace::DATASET_OVERTURE_IMPORT,
            'dataset_id' => null,
            'name' => fake()->company(),
            'category' => 'restaurant',
            'mapped_category' => BusinessCategory::Restaurant,
            'confidence' => 0.9,
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'observed_at' => now(),
        ];
    }

    public function overture(): static
    {
        return $this->state(['source' => 'overture', 'dataset_type' => SourcePlace::DATASET_OVERTURE_IMPORT]);
    }

    /**
     * A branch listed by a brand's official store locator.
     */
    public function storeLocator(AtpSpider $spider): static
    {
        return $this->state(fn (): array => [
            'source' => 'alltheplaces',
            'source_record_id' => $spider->spider.'/'.fake()->unique()->numberBetween(1, 999_999),
            'dataset_type' => SourcePlace::DATASET_ATP_SPIDER,
            'dataset_id' => $spider->getKey(),
            'brand_name' => $spider->brand_name,
            'brand_wikidata' => $spider->brand_wikidata,
            'confidence' => null,
            'provenance' => [['dataset' => 'AllThePlaces', 'spider' => $spider->spider]],
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\AtpSpider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AtpSpider>
 */
class AtpSpiderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spider' => fake()->unique()->lexify('brand_????'),
            'brand_name' => fake()->company(),
            'brand_wikidata' => null,
            'country_code' => null,
            'enabled' => true,
        ];
    }
}

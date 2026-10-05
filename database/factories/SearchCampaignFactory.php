<?php

namespace Database\Factories;

use App\Enums\BusinessCategory;
use App\Enums\CampaignStatus;
use App\Enums\Country;
use App\Models\SearchCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchCampaign>
 */
class SearchCampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Riyadh Restaurants',
            'provider' => 'openstreetmap',
            'country_code' => Country::SaudiArabia,
            'city' => 'Riyadh',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'radius_meters' => 2000,
            'category' => BusinessCategory::Restaurant,
            'keywords' => null,
            'status' => CampaignStatus::Pending,
        ];
    }

    public function withoutCoordinates(): static
    {
        return $this->state(['latitude' => null, 'longitude' => null]);
    }

    public function status(CampaignStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}

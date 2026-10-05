<?php

namespace Database\Factories;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Models\Business;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory()->state(['website' => null]),
            'type' => OpportunityType::WebsiteMissing,
            'priority' => OpportunityPriority::High,
            'reasons' => ['Business has no website'],
            'rule' => 'website_missing',
            'detected_at' => now(),
        ];
    }
}

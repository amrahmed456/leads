<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Business;
use App\Models\LeadScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScore>
 */
class LeadScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'score' => 50,
            'status' => LeadStatus::Warm,
            'data_confidence_score' => 50,
            'business_fit_score' => 60,
            'opportunity_score' => 45,
            'breakdown' => [['key' => 'opportunity', 'label' => 'Opportunity 45 × 45%', 'points' => 20]],
            'eligibility_reasons' => [['code' => 'MEETS_WARM_CRITERIA', 'label' => 'Meets the WARM requirements']],
            'version' => '2',
            'scored_at' => now(),
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(['status' => $status, 'score' => $status === LeadStatus::Disqualified ? 0 : 50]);
    }
}

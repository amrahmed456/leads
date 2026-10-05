<?php

namespace Database\Factories;

use App\Enums\LeadGrade;
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
            'grade' => LeadGrade::Warm,
            'breakdown' => [['key' => 'has_phone', 'label' => 'Phone number available', 'points' => 8]],
            'version' => '1',
            'scored_at' => now(),
        ];
    }
}

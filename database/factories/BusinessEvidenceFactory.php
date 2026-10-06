<?php

namespace Database\Factories;

use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Enums\SourceRole;
use App\Identity\Evidence\EvidenceRecorder;
use App\Models\Business;
use App\Models\BusinessEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessEvidence>
 */
class BusinessEvidenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'fact_type' => EvidenceFact::Existence,
            'value' => 'Example',
            'value_normalized' => 'example',
            'provider' => 'openstreetmap',
            'source_role' => SourceRole::Discovery,
            'independence_group' => 'osm_family',
            'source_record_id' => 'node/'.fake()->unique()->numberBetween(1, 9_999_999),
            'status' => EvidenceStatus::Active,
            'observed_at' => now(),
            'last_seen_at' => now(),
            'fingerprint' => fake()->unique()->sha1(),
        ];
    }

    /**
     * A fact with its comparison value derived the way the recorder derives it.
     */
    public function fact(EvidenceFact $fact, string $value): static
    {
        return $this->state(['fact_type' => $fact, 'value' => $value, 'value_normalized' => EvidenceRecorder::normalize($fact, $value)]);
    }

    public function from(string $provider, string $group, SourceRole $role = SourceRole::Discovery): static
    {
        return $this->state(['provider' => $provider, 'independence_group' => $group, 'source_role' => $role]);
    }
}

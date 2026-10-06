<?php

namespace App\Identity\Evidence;

use App\Models\BusinessEvidence;
use Illuminate\Support\Collection;

/**
 * The reconciled state of a business's evidence.
 */
final readonly class ReconciliationResult
{
    /**
     * @param  Collection<int, BusinessEvidence>  $evidence  Current (non-stale) evidence
     * @param  list<string>  $existenceGroups  Independent groups confirming the business exists
     * @param  list<array{fact: string, severity: string, values: list<array{value: string, provider: string, group: string}>, preferred: string}>  $conflicts
     */
    public function __construct(
        public Collection $evidence,
        public array $existenceGroups,
        public array $conflicts,
        public bool $phoneCorroborated,
        public bool $locationCorroborated,
        public ?float $sourceConfidence,
        public bool $staleOnly,
    ) {}

    public function hasMajorConflict(): bool
    {
        foreach ($this->conflicts as $conflict) {
            if ($conflict['severity'] === 'major') {
                return true;
            }
        }

        return false;
    }

    public function minorConflictCount(): int
    {
        return count(array_filter($this->conflicts, fn (array $conflict): bool => $conflict['severity'] !== 'major'));
    }

    public function hasFirstPartyConfirmation(): bool
    {
        return array_intersect($this->existenceGroups, ['first_party', 'first_party_web']) !== [];
    }
}

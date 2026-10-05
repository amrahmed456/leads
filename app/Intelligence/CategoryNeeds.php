<?php

namespace App\Intelligence;

use App\Enums\BusinessCategory;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Models\Business;

/**
 * Which online capabilities a business needs, from its category and type
 * (config/intelligence.php "category_needs" and "type_exclusions").
 */
final class CategoryNeeds
{
    /**
     * @param  array<string, array<string, string>>  $needs  Category => [capability => priority]
     * @param  array<string, list<string>>  $typeExclusions  Capability => business types it does not apply to
     */
    public function __construct(
        private readonly array $needs,
        private readonly array $typeExclusions = [],
    ) {}

    /**
     * @return array<string, OpportunityPriority> Capability (OpportunityType value) => priority
     */
    public function forBusiness(Business $business): array
    {
        return $this->for($business->category, $business->type);
    }

    /**
     * @return array<string, OpportunityPriority> Capability (OpportunityType value) => priority
     */
    public function for(?BusinessCategory $category, ?string $type = null): array
    {
        $needs = [];

        foreach ($this->needs[$category?->value] ?? [] as $capability => $priority) {
            $capability = OpportunityType::tryFrom($capability);
            $priority = OpportunityPriority::tryFrom($priority);

            if ($capability === null || $priority === null || in_array($type, $this->typeExclusions[$capability->value] ?? [], true)) {
                continue;
            }

            $needs[$capability->value] = $priority;
        }

        return $needs;
    }
}

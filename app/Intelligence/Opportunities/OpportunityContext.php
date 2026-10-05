<?php

namespace App\Intelligence\Opportunities;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Models\Business;
use App\Models\WebsiteAudit;

/**
 * Everything an opportunity rule may look at.
 */
final readonly class OpportunityContext
{
    /**
     * @param  WebsiteAudit|null  $audit  Latest finished audit of the current website
     * @param  array<string, OpportunityPriority>  $needs  Capabilities the category needs
     */
    public function __construct(
        public Business $business,
        public ?WebsiteAudit $audit,
        public array $needs,
    ) {}

    public function hasWebsite(): bool
    {
        return $this->business->website !== null;
    }

    /**
     * The audit, but only if it analyzed the homepage. When the audit was
     * blocked or failed, website features are unknown and must not be
     * treated as missing.
     */
    public function analyzedAudit(): ?WebsiteAudit
    {
        return $this->audit?->isAnalyzed() ? $this->audit : null;
    }

    public function need(OpportunityType $capability): ?OpportunityPriority
    {
        return $this->needs[$capability->value] ?? null;
    }

    public function categoryLabel(): string
    {
        return $this->business->category?->label() ?? 'Businesses like this';
    }
}

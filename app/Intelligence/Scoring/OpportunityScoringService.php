<?php

namespace App\Intelligence\Scoring;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\CategoryNeeds;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Models\Business;
use App\Models\WebsiteAudit;

/**
 * "Does the business have digital gaps we can fix?" — from 0 to 100, every
 * point explained (config/intelligence.php "opportunity_score").
 *
 *  1. Website status: missing, broken, weak or average website.
 *  2. Opportunities: what could be sold (capped), plus the category's needs
 *     when there is no website at all.
 *  3. An excellent existing digital presence lowers the score.
 *
 * How reachable or how real the business is lives in other scores.
 */
final class OpportunityScoringService
{
    /**
     * @param  array{points: array<string, int>, website_weak_below: int, website_average_below: int, opportunity_points_cap: int, strong_presence_min_audit_score: int}  $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly CategoryNeeds $needs,
    ) {}

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     */
    public function score(Business $business, ?WebsiteAudit $audit, array $opportunities): ScoreResult
    {
        return ScoreResult::fromBreakdown([
            ...$this->websiteStatus($business, $audit),
            ...$this->opportunities($business, $audit, $opportunities),
            ...$this->strongPresence($audit, $opportunities),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, points: int}>
     */
    private function websiteStatus(Business $business, ?WebsiteAudit $audit): array
    {
        if ($business->website === null) {
            return [$this->item('website_missing', 'No website')];
        }

        if ($audit?->showsBrokenWebsite()) {
            return [$this->item('website_broken', 'Listed website is down or broken')];
        }

        if ($audit === null || ! $audit->isAnalyzed() || $audit->score === null) {
            return [$this->item('website_not_audited', 'Website not audited yet', 0)];
        }

        return [match (true) {
            $audit->score < $this->rules['website_weak_below'] => $this->item('website_weak', "Weak website (audit score {$audit->score}/100)"),
            $audit->score < $this->rules['website_average_below'] => $this->item('website_average', "Average website (audit score {$audit->score}/100)"),
            default => $this->item('website_strong', "Strong website (audit score {$audit->score}/100)", 0),
        }];
    }

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     * @return list<array{key: string, label: string, points: int}>
     */
    private function opportunities(Business $business, ?WebsiteAudit $audit, array $opportunities): array
    {
        $items = [];

        // Without a website, everything the category needs is still to be built.
        if ($business->website === null) {
            foreach ($this->needs->forBusiness($business) as $capability => $priority) {
                if ($priority !== OpportunityPriority::Low) {
                    $items[] = $this->item("latent_need_{$priority->value}", 'Category needs '.mb_strtolower(OpportunityType::from($capability)->label()));
                }
            }
        }

        $remaining = (int) $this->rules['opportunity_points_cap'];

        foreach ($opportunities as $opportunity) {
            // Already counted in the website status.
            if ($opportunity->type === OpportunityType::WebsiteMissing
                || ($opportunity->type === OpportunityType::WebsiteRedesign && $audit?->showsBrokenWebsite())) {
                continue;
            }

            $points = min($this->points("opportunity_{$opportunity->priority->value}"), $remaining);
            $remaining -= $points;

            $items[] = [
                'key' => "opportunity_{$opportunity->type->value}",
                'label' => "{$opportunity->type->label()} opportunity ({$opportunity->priority->value} priority)",
                'points' => $points,
            ];
        }

        return $items;
    }

    /**
     * A high-scoring website without any high-priority gap: little to sell.
     *
     * @param  list<DetectedOpportunity>  $opportunities
     * @return list<array{key: string, label: string, points: int}>
     */
    private function strongPresence(?WebsiteAudit $audit, array $opportunities): array
    {
        $highGap = array_filter($opportunities, fn (DetectedOpportunity $opportunity): bool => $opportunity->priority === OpportunityPriority::High);

        if (! $audit?->isAnalyzed() || $audit->score === null || $audit->score < $this->rules['strong_presence_min_audit_score'] || $highGap !== []) {
            return [];
        }

        return [$this->item('strong_digital_presence', "Strong existing digital presence (audit score {$audit->score}/100, no high-priority gap)")];
    }

    /**
     * @return array{key: string, label: string, points: int}
     */
    private function item(string $key, string $label, ?int $points = null): array
    {
        return ['key' => $key, 'label' => $label, 'points' => $points ?? $this->points($key)];
    }

    private function points(string $key): int
    {
        return (int) ($this->rules['points'][$key] ?? 0);
    }
}

<?php

namespace App\Intelligence\Scoring;

use App\Enums\LeadGrade;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\CategoryNeeds;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Support\Str;

/**
 * Rule-based lead score from 0 to 100.
 *
 * Every rule that contributes is recorded with its label and points, so a
 * score can always be explained. Weights live in config/intelligence.php.
 *
 *  1. Website status: missing, broken, weak or average website.
 *  2. Opportunities: what could be sold (capped), plus category needs when
 *     there is no website at all.
 *  3. Reachability: phone, e-mail, WhatsApp, social profiles.
 *  4. Profile: verified location and category value.
 */
final class LeadScoringService
{
    /**
     * @param  array{version: string, points: array<string, int>, website_weak_below: int, website_average_below: int, opportunity_points_cap: int, grades: array{hot: int, warm: int}}  $rules
     * @param  array{high?: list<string>, medium?: list<string>}  $categoryValue
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $categoryValue,
        private readonly CategoryNeeds $needs,
    ) {}

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     */
    public function score(Business $business, ?WebsiteAudit $audit, array $opportunities): LeadScoreResult
    {
        $analyzed = $audit?->isAnalyzed() ? $audit : null;

        $breakdown = [
            ...$this->websiteStatus($business, $audit),
            ...$this->opportunities($business, $audit, $opportunities),
            ...$this->reachability($business, $analyzed),
            ...$this->profile($business),
        ];

        $score = max(0, min(100, array_sum(array_column($breakdown, 'points'))));

        return new LeadScoreResult($score, $this->grade($score), $breakdown, (string) $this->rules['version']);
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
     * @return list<array{key: string, label: string, points: int}>
     */
    private function reachability(Business $business, ?WebsiteAudit $audit): array
    {
        $phone = $business->phone ?? ($audit?->signalData(WebsiteSignal::Phone)['numbers'][0] ?? null);
        $email = $business->email ?? ($audit?->signalData(WebsiteSignal::Email)['addresses'][0] ?? null);
        $whatsapp = isset($business->social_links['whatsapp']) || $audit?->has_whatsapp === true;
        $networks = array_unique([
            ...array_keys(array_diff_key($business->social_links ?? [], ['whatsapp' => true])),
            ...array_keys($audit?->signalData(WebsiteSignal::SocialProfiles)['profiles'] ?? []),
        ]);

        $items = [];

        if ($phone !== null) {
            $items[] = $this->item('phone', $business->phone !== null ? 'Phone number available' : 'Phone number found on the website');
        }

        if ($email !== null) {
            $items[] = $this->item('email', $business->email !== null ? 'E-mail address available' : 'E-mail address found on the website');
        }

        if ($whatsapp) {
            $items[] = $this->item('whatsapp', 'WhatsApp available');
        }

        if ($networks !== []) {
            $items[] = $this->item('social', 'Active on '.implode(', ', array_map(Str::headline(...), $networks)));
        }

        if ($items === []) {
            $items[] = $this->item('no_contact', 'No phone, e-mail, WhatsApp or social profile found');
        }

        return $items;
    }

    /**
     * @return list<array{key: string, label: string, points: int}>
     */
    private function profile(Business $business): array
    {
        $items = [];

        if ($business->latitude !== null || $business->address !== null) {
            $items[] = $this->item('location', 'Verified physical location');
        }

        $category = $business->category;

        if ($category !== null && in_array($category->value, $this->categoryValue['high'] ?? [], true)) {
            $items[] = $this->item('category_high', "High-value category: {$category->label()}");
        } elseif ($category !== null && in_array($category->value, $this->categoryValue['medium'] ?? [], true)) {
            $items[] = $this->item('category_medium', "Category: {$category->label()}");
        }

        return $items;
    }

    private function grade(int $score): LeadGrade
    {
        return match (true) {
            $score >= $this->rules['grades']['hot'] => LeadGrade::Hot,
            $score >= $this->rules['grades']['warm'] => LeadGrade::Warm,
            default => LeadGrade::Cold,
        };
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

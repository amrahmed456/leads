<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\Confidence;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A website that is broken, a placeholder, outdated, or technically weak.
 */
final class WebsiteRedesignRule implements OpportunityRule
{
    public function __construct(private readonly int $redesignBelowScore) {}

    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $audit = $context->audit;

        if (! $context->hasWebsite() || $audit === null) {
            return null;
        }

        if ($audit->showsBrokenWebsite()) {
            return new DetectedOpportunity(OpportunityType::WebsiteRedesign, OpportunityPriority::High, array_values(array_filter([
                'The listed website is not working: '.lcfirst((string) $audit->failure_type?->label()),
                $audit->http_status !== null ? "The server answered with HTTP {$audit->http_status}" : null,
                'Customers who look the business up find a broken website',
            ])), 'broken_website');
        }

        if (! $audit->isAnalyzed()) {
            return null;
        }

        if ($audit->signalData(WebsiteSignal::OutdatedContent)['placeholder'] ?? false) {
            return new DetectedOpportunity(OpportunityType::WebsiteRedesign, OpportunityPriority::High, [
                'The website is only a placeholder page',
                ...array_slice($audit->signalEvidence(WebsiteSignal::OutdatedContent), 0, 1),
            ], 'placeholder_website');
        }

        $issues = [];

        if (! $audit->is_https) {
            $issues[] = 'Not served over HTTPS';
        } elseif ($audit->ssl_valid === false) {
            $issues[] = 'Invalid SSL certificate';
        }

        if (! $audit->has_viewport) {
            $issues[] = 'No mobile viewport';
        }

        $outdated = $audit->signalConfidence(WebsiteSignal::OutdatedContent)->atLeast(Confidence::Medium);

        if ($outdated) {
            $issues[] = $audit->signalEvidence(WebsiteSignal::OutdatedContent)[0] ?? 'Signs of an outdated website';
        }

        $lowScore = $audit->score !== null && $audit->score < $this->redesignBelowScore;

        if ($lowScore) {
            $issues[] = "Low website audit score ({$audit->score}/100)";
        }

        if (! $lowScore && ! $outdated && count($issues) < 2) {
            return null;
        }

        $priority = count($issues) >= 3 || ($audit->score !== null && $audit->score < 25)
            ? OpportunityPriority::High
            : OpportunityPriority::Medium;

        return new DetectedOpportunity(OpportunityType::WebsiteRedesign, $priority, $issues, 'weak_website');
    }
}

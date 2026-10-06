<?php

namespace App\Intelligence;

use App\Enums\AuditStatus;
use App\Identity\BusinessIdentityAnalyzer;
use App\Intelligence\Eligibility\ContactRoutes;
use App\Intelligence\Eligibility\EligibilityInput;
use App\Intelligence\Eligibility\LeadEligibilityService;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityDetector;
use App\Intelligence\Scoring\BusinessFitService;
use App\Intelligence\Scoring\DataConfidenceService;
use App\Intelligence\Scoring\OpportunityScoringService;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\WebsiteAudit;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Re-evaluates a business from everything known about it. Safe to run any
 * number of times.
 *
 *   identity (evidence, brand, entity type) → opportunities →
 *   data confidence + business fit + opportunity score → lead eligibility.
 *
 * The three scores are separate on purpose: more sources raise confidence,
 * digital gaps raise opportunity, chain evidence lowers fit and eligibility.
 */
final class BusinessEvaluator
{
    public function __construct(
        private readonly BusinessIdentityAnalyzer $identity,
        private readonly OpportunityDetector $opportunities,
        private readonly DataConfidenceService $dataConfidence,
        private readonly BusinessFitService $businessFit,
        private readonly OpportunityScoringService $opportunityScoring,
        private readonly LeadEligibilityService $eligibility,
        private readonly ContactRoutes $contactRoutes,
        private readonly UrlNormalizer $urls,
        private readonly string $version,
    ) {}

    public function evaluate(Business $business): LeadScore
    {
        $audit = $this->relevantAudit($business);
        $identity = $this->identity->analyze($business, $audit);

        $detected = $this->opportunities->detect($business, $audit);
        $confidence = $this->dataConfidence->score($business, $identity->evidence, $audit);
        $fit = $this->businessFit->score($business);
        $opportunity = $this->opportunityScoring->score($business, $audit, $detected);

        $result = $this->eligibility->evaluate(new EligibilityInput(
            business: $business,
            entity: $identity->classification,
            evidence: $identity->evidence,
            dataConfidence: $confidence,
            businessFit: $fit,
            opportunity: $opportunity,
            opportunities: $detected,
            contactRoutes: $this->contactRoutes->for($business, $audit),
        ));

        return DB::transaction(function () use ($business, $audit, $detected, $confidence, $fit, $opportunity, $result): LeadScore {
            $this->syncOpportunities($business, $audit, $detected);

            return $business->leadScore()->updateOrCreate([], [
                'score' => $result->score,
                'status' => $result->status,
                'data_confidence_score' => $confidence->score,
                'business_fit_score' => $fit->score,
                'opportunity_score' => $opportunity->score,
                'breakdown' => $result->breakdown,
                'eligibility_reasons' => $result->reasons,
                'confidence_breakdown' => $confidence->breakdown,
                'fit_breakdown' => $fit->breakdown,
                'opportunity_breakdown' => $opportunity->breakdown,
                'version' => $this->version,
                'scored_at' => now(),
            ]);
        });
    }

    /**
     * The latest finished audit, provided it concerns the business's current
     * website (an audit of a previous URL says nothing about the new one).
     */
    public function relevantAudit(Business $business): ?WebsiteAudit
    {
        $audit = $business->websiteAudits()
            ->whereIn('status', [AuditStatus::Completed, AuditStatus::Failed, AuditStatus::NotApplicable])
            ->latest('id')
            ->first();

        if ($audit === null) {
            return null;
        }

        if ($business->website === null) {
            return $audit->status === AuditStatus::NotApplicable ? $audit : null;
        }

        $currentUrl = $this->urls->normalize($business->website) ?? $business->website;

        return $audit->status !== AuditStatus::NotApplicable && $audit->normalized_url === $currentUrl ? $audit : null;
    }

    /**
     * @param  list<DetectedOpportunity>  $detected
     */
    private function syncOpportunities(Business $business, ?WebsiteAudit $audit, array $detected): void
    {
        $business->opportunities()
            ->whereNotIn('type', array_map(fn (DetectedOpportunity $opportunity): string => $opportunity->type->value, $detected))
            ->delete();

        foreach ($detected as $opportunity) {
            $model = $business->opportunities()->firstOrNew(['type' => $opportunity->type]);

            $model->fill([
                'priority' => $opportunity->priority,
                'reasons' => $opportunity->reasons,
                'rule' => $opportunity->rule,
                'website_audit_id' => $audit?->getKey(),
                'detected_at' => $model->exists ? $model->detected_at : now(),
            ])->save();
        }
    }
}

<?php

namespace App\Intelligence;

use App\Enums\AuditStatus;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityDetector;
use App\Intelligence\Scoring\LeadScoringService;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\WebsiteAudit;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates a business's opportunities and lead score from its data and
 * its latest finished website audit. Safe to run any number of times.
 */
final class BusinessEvaluator
{
    public function __construct(
        private readonly OpportunityDetector $opportunities,
        private readonly LeadScoringService $scoring,
        private readonly UrlNormalizer $urls,
    ) {}

    public function evaluate(Business $business): LeadScore
    {
        $audit = $this->relevantAudit($business);
        $detected = $this->opportunities->detect($business, $audit);
        $result = $this->scoring->score($business, $audit, $detected);

        return DB::transaction(function () use ($business, $audit, $detected, $result): LeadScore {
            $this->syncOpportunities($business, $audit, $detected);

            return $business->leadScore()->updateOrCreate([], [
                'score' => $result->score,
                'grade' => $result->grade,
                'breakdown' => $result->breakdown,
                'version' => $result->version,
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

<?php

namespace App\Providers;

use App\Auditing\AuditScorer;
use App\Auditing\Contracts\HostResolver;
use App\Auditing\CorporatePageDetector;
use App\Auditing\DnsHostResolver;
use App\Auditing\StructuredDataExtractor;
use App\Auditing\UrlGuard;
use App\Auditing\WebsiteAnalyzer;
use App\Auditing\WebsiteAuditScheduler;
use App\Auditing\WebsiteAuditService;
use App\Auditing\WebsiteFetcher;
use App\Identity\BusinessIdentityAnalyzer;
use App\Intelligence\BusinessEvaluator;
use App\Intelligence\CategoryNeeds;
use App\Intelligence\Eligibility\ContactRoutes;
use App\Intelligence\Eligibility\LeadEligibilityService;
use App\Intelligence\Opportunities\OpportunityDetector;
use App\Intelligence\Opportunities\Rules\WebsiteRedesignRule;
use App\Intelligence\Scoring\BusinessFitService;
use App\Intelligence\Scoring\DataConfidenceService;
use App\Intelligence\Scoring\OpportunityScoringService;
use App\Jobs\AuditBusinessWebsite;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the website audit, opportunity and lead scoring services to their
 * configuration (config/auditing.php and config/intelligence.php).
 */
class IntelligenceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        $this->app->bind(WebsiteFetcher::class, fn (Application $app) => new WebsiteFetcher(
            $app->make(UrlGuard::class),
            (array) config('auditing.http'),
        ));

        $this->app->bind(AuditScorer::class, fn () => new AuditScorer((array) config('auditing.score')));

        $this->app->bind(WebsiteAuditService::class, fn (Application $app) => new WebsiteAuditService(
            $app->make(WebsiteFetcher::class),
            $app->make(WebsiteAnalyzer::class),
            $app->make(AuditScorer::class),
            $app->make(CategoryNeeds::class),
            $app->make(UrlNormalizer::class),
            $app->make(StructuredDataExtractor::class),
            $app->make(CorporatePageDetector::class),
            freshDays: (int) config('auditing.freshness.completed_days'),
        ));

        $this->app->bind(WebsiteAuditScheduler::class, fn (Application $app) => new WebsiteAuditScheduler(
            $app->make(BusinessEvaluator::class),
            $app->make(UrlNormalizer::class),
            freshDays: (int) config('auditing.freshness.completed_days'),
            retryFailedAfterHours: (int) config('auditing.freshness.failed_retry_hours'),
            perMinute: (int) config('auditing.per_minute'),
        ));

        $this->app->bind(CategoryNeeds::class, fn () => new CategoryNeeds(
            (array) config('intelligence.category_needs'),
            (array) config('intelligence.type_exclusions'),
        ));

        $this->app->bind(WebsiteRedesignRule::class, fn () => new WebsiteRedesignRule(
            (int) config('intelligence.opportunities.redesign_below_score'),
        ));

        $this->app->bind(OpportunityScoringService::class, fn (Application $app) => new OpportunityScoringService(
            (array) config('intelligence.opportunity_score'),
            $app->make(CategoryNeeds::class),
        ));

        $this->app->bind(DataConfidenceService::class, fn () => new DataConfidenceService(
            (array) config('intelligence.data_confidence'),
            (int) config('intelligence.identity.recent_within_days'),
        ));

        $this->app->bind(BusinessFitService::class, fn () => new BusinessFitService((array) config('intelligence.business_fit')));

        $this->app->bind(LeadEligibilityService::class, fn () => new LeadEligibilityService(
            (array) config('intelligence.eligibility'),
            (int) config('intelligence.entity.disqualify_min_confidence'),
            (array) config('intelligence.services_offered'),
            (array) config('intelligence.lead_score.weights'),
        ));

        $this->app->bind(BusinessEvaluator::class, fn (Application $app) => new BusinessEvaluator(
            $app->make(BusinessIdentityAnalyzer::class),
            $app->make(OpportunityDetector::class),
            $app->make(DataConfidenceService::class),
            $app->make(BusinessFitService::class),
            $app->make(OpportunityScoringService::class),
            $app->make(LeadEligibilityService::class),
            $app->make(ContactRoutes::class),
            $app->make(UrlNormalizer::class),
            version: (string) config('intelligence.lead_score.version'),
        ));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Caps website audits across all workers; audits run from the command line pace themselves.
        RateLimiter::for('website-audits', fn (AuditBusinessWebsite $job): Limit => $job->runsSynchronously()
            ? Limit::none()
            : Limit::perMinute(max(1, (int) config('auditing.per_minute'))));
    }
}

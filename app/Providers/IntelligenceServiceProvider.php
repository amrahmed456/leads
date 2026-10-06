<?php

namespace App\Providers;

use App\Auditing\AuditScorer;
use App\Auditing\Contracts\HostResolver;
use App\Auditing\DnsHostResolver;
use App\Auditing\UrlGuard;
use App\Auditing\WebsiteAnalyzer;
use App\Auditing\WebsiteAuditScheduler;
use App\Auditing\WebsiteAuditService;
use App\Auditing\WebsiteFetcher;
use App\Intelligence\BusinessEvaluator;
use App\Intelligence\CategoryNeeds;
use App\Intelligence\Opportunities\Rules\WebsiteRedesignRule;
use App\Intelligence\Outreach\OutreachMessageComposer;
use App\Intelligence\Outreach\WhatsAppNumberResolver;
use App\Intelligence\Scoring\LeadScoringService;
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

        $this->app->bind(LeadScoringService::class, fn (Application $app) => new LeadScoringService(
            (array) config('intelligence.lead_score'),
            (array) config('intelligence.category_value'),
            $app->make(CategoryNeeds::class),
        ));

        $this->app->bind(OutreachMessageComposer::class, fn (Application $app) => new OutreachMessageComposer(
            $app->make(WhatsAppNumberResolver::class),
            $app->make(CategoryNeeds::class),
            senderName: config('intelligence.outreach.sender_name') ?: null,
            senderCompany: config('intelligence.outreach.sender_company') ?: null,
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

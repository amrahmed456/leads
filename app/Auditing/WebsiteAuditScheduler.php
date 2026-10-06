<?php

namespace App\Auditing;

use App\Enums\AuditStatus;
use App\Intelligence\BusinessEvaluator;
use App\Jobs\AuditBusinessWebsite;
use App\Models\Business;
use App\Models\WebsiteAudit;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Decides when a website is audited and queues the work.
 *
 * - At most one active (queued or running) audit per business; enforced
 *   under a row lock, so concurrent requests cannot both queue one.
 * - Freshness: a recent successful audit of the same URL is not repeated,
 *   and a failed one is retried only after a delay. Manual re-audits
 *   ($force) skip the freshness check, never the duplicate check.
 * - Batches are spread out with increasing delays (audits per minute).
 */
final class WebsiteAuditScheduler
{
    public function __construct(
        private readonly BusinessEvaluator $evaluator,
        private readonly UrlNormalizer $urls,
        private readonly int $freshDays,
        private readonly int $retryFailedAfterHours,
        private readonly int $perMinute,
    ) {}

    /**
     * Queue an audit of the business's website. A business without a website
     * gets a "not applicable" record instead.
     *
     * @param  bool  $runNow  Run the audit in this process (command line) instead of queueing it
     * @return WebsiteAudit|null The new audit, or null when none was needed or allowed
     */
    public function queue(Business $business, bool $force = false, int $delaySeconds = 0, bool $runNow = false): ?WebsiteAudit
    {
        if ($business->website === null) {
            $this->recordNoWebsite($business);

            return null;
        }

        $url = $this->urls->normalize($business->website) ?? $business->website;

        $audit = DB::transaction(function () use ($business, $url, $force): ?WebsiteAudit {
            Business::query()->whereKey($business->getKey())->lockForUpdate()->first();

            if ($business->websiteAudits()->whereIn('status', [AuditStatus::Pending, AuditStatus::Running])->exists()) {
                return null;
            }

            $latest = $business->websiteAudits()->whereNotNull('audited_at')->latest('id')->first();

            if (! $force && $latest !== null && $this->isFresh($latest, $url)) {
                return null;
            }

            $audit = $business->websiteAudits()->make(['url' => $business->website, 'normalized_url' => $url, 'queued_at' => now()]);
            $audit->forceFill(['forced' => $force])->save();

            return $audit;
        });

        if ($audit === null) {
            return null;
        }

        if ($runNow) {
            AuditBusinessWebsite::dispatchSync($audit);
        } else {
            AuditBusinessWebsite::dispatch($audit)->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null);
        }

        return $audit;
    }

    /**
     * Whether an audit still describes the website well enough not to repeat it.
     */
    public function isFresh(WebsiteAudit $audit, string $normalizedUrl): bool
    {
        if ($audit->normalized_url !== $normalizedUrl || $audit->audited_at === null) {
            return false;
        }

        return match ($audit->status) {
            AuditStatus::Completed => $audit->audited_at->greaterThan(now()->subDays($this->freshDays)),
            AuditStatus::Failed => $audit->audited_at->greaterThan(now()->subHours($this->retryFailedAfterHours)),
            default => false,
        };
    }

    /**
     * Prepare many businesses, e.g. after a discovery campaign: those without
     * a website are evaluated straight away (website_missing opportunity);
     * the others get an audit queued, spaced out to respect the per-minute
     * limit. One failing business never stops the batch.
     *
     * @param  Builder<Business>|BelongsToMany<Business, *>  $businesses
     * @param  bool  $evaluate  Evaluate each business now; queued jobs leave it to chunked re-evaluation jobs
     * @return array{queued: int, without_website: int, skipped: int, failed: int}
     */
    public function queueMany(Builder|BelongsToMany $businesses, bool $force = false, bool $runNow = false, bool $evaluate = true): array
    {
        $summary = ['queued' => 0, 'without_website' => 0, 'skipped' => 0, 'failed' => 0];
        $spacingSeconds = 60 / max(1, $this->perMinute);

        $businesses->chunkById(200, function (Collection $chunk) use (&$summary, $spacingSeconds, $force, $runNow, $evaluate): void {
            foreach ($chunk as $business) {
                try {
                    if ($runNow && $summary['queued'] > 0 && $business->website !== null) {
                        // Synchronous runs bypass the queue's rate limiter, so pace them here.
                        Sleep::for($spacingSeconds)->seconds();
                    }

                    if ($business->website === null) {
                        $this->recordNoWebsite($business);
                        $summary['without_website']++;
                    } elseif ($this->queue($business, $force, (int) round($summary['queued'] * $spacingSeconds), $runNow) !== null) {
                        $summary['queued']++;
                    } else {
                        $summary['skipped']++;
                    }

                    // Score immediately with what is known; audits refine it when they finish.
                    if ($evaluate) {
                        $this->evaluator->evaluate($business);
                    }
                } catch (Throwable $e) {
                    $summary['failed']++;

                    Log::channel('audits')->error('Could not prepare a business for auditing.', [
                        'business_id' => $business->getKey(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }, 'businesses.id', 'id');

        return $summary;
    }

    private function recordNoWebsite(Business $business): void
    {
        $latest = $business->websiteAudits()->latest('id')->first();

        if ($latest?->status === AuditStatus::NotApplicable) {
            $latest->forceFill(['audited_at' => now()])->save();

            return;
        }

        $business->websiteAudits()->make()->forceFill([
            'status' => AuditStatus::NotApplicable,
            'audited_at' => now(),
        ])->save();
    }
}

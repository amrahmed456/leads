<?php

namespace App\Jobs;

use App\Auditing\Exceptions\WebsiteFetchException;
use App\Auditing\WebsiteAuditService;
use App\Enums\AuditFailure;
use App\Intelligence\BusinessEvaluator;
use App\Models\WebsiteAudit;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audits one business website, then re-evaluates the business's
 * opportunities and lead score.
 *
 * Temporary failures (timeouts, 429, 5xx) are retried with growing delays,
 * up to auditing.retries.max_attempts; permanent ones are recorded at once.
 * A failing website only ever fails its own audit.
 */
class AuditBusinessWebsite implements ShouldQueue
{
    use Queueable;

    /** Seconds; stays well below the queue's retry_after (600). */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /** Unexpected exceptions (bugs, database errors) tolerated before the job fails. */
    public int $maxExceptions = 2;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebsiteAudit $audit)
    {
        $this->onQueue(config('auditing.queue'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('website-audits')];
    }

    /**
     * Rate limiting releases the job, which counts as an attempt, so the job
     * is bounded by time rather than by its number of attempts.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(WebsiteAuditService $audits, BusinessEvaluator $evaluator): void
    {
        if (! $this->audit->status->isActive()) {
            return;
        }

        try {
            $audits->run($this->audit);
        } catch (WebsiteFetchException $e) {
            if ($this->audit->attempts < config('auditing.retries.max_attempts') && ! $this->runsSynchronously()) {
                $this->retryLater($e);

                return;
            }

            $audits->fail($this->audit, $e);
        }

        $evaluator->evaluate($this->audit->business);
    }

    /**
     * Called when the job fails unexpectedly (bug, timeout, retry window over).
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->audit->status->isActive()) {
            $this->audit->markFailed(AuditFailure::Unexpected, $exception?->getMessage() ?: AuditFailure::Unexpected->label());
        }

        Log::channel('audits')->error('Website audit job failed.', [
            'audit_id' => $this->audit->getKey(),
            'business_id' => $this->audit->business_id,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    public function runsSynchronously(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }

    private function retryLater(WebsiteFetchException $e): void
    {
        $delays = (array) config('auditing.retries.delays');
        $delay = $e->retryAfterSeconds !== null
            ? min(max($e->retryAfterSeconds, 60), 3600)
            : (int) ($delays[$this->audit->attempts - 1] ?? end($delays));

        $this->audit->markRetrying($e->failure, $e->getMessage());

        Log::channel('audits')->warning('Website audit failed temporarily; will retry.', [
            'audit_id' => $this->audit->getKey(),
            'url' => $this->audit->url,
            'failure' => $e->failure->value,
            'attempt' => $this->audit->attempts,
            'retry_in_seconds' => $delay,
        ]);

        $this->release($delay);
    }
}

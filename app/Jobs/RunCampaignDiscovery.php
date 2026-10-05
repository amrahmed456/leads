<?php

namespace App\Jobs;

use App\Discovery\CampaignDiscoveryService;
use App\Discovery\Exceptions\DiscoveryException;
use App\Models\SearchCampaign;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs business discovery for one campaign in the background.
 *
 * Transient failures (timeouts, rate limits, provider outages) are retried
 * with a delay; permanent failures fail the campaign straight away.
 */
class RunCampaignDiscovery implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds; queue retry_after must be larger (see config/queue.php). */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** Seconds before a stuck job's uniqueness lock expires. */
    public int $uniqueFor = 3600;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> Seconds to wait before the 2nd and 3rd attempts. */
    public array $retryDelays = [60, 300];

    public function __construct(public SearchCampaign $campaign)
    {
        $this->onQueue(config('discovery.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->campaign->getKey();
    }

    public function handle(CampaignDiscoveryService $discovery): void
    {
        try {
            $discovery->run($this->campaign);
        } catch (Throwable $e) {
            $retryable = ! $e instanceof DiscoveryException || $e->retryable;

            if ($retryable && $this->attempts() < $this->tries && ! $this->runsSynchronously()) {
                $delay = $this->retryDelays[$this->attempts() - 1] ?? end($this->retryDelays);

                Log::channel('discovery')->warning('Campaign discovery attempt failed; will retry.', [
                    ...$this->logContext($e),
                    'retry_in_seconds' => $delay,
                ]);

                $this->campaign->markRetrying($this->attempts(), $e->getMessage());
                $this->release($delay);

                return;
            }

            // fail() marks the queued job as failed and then calls failed().
            $this->job !== null ? $this->fail($e) : $this->failed($e);
        }
    }

    /**
     * Called once the job has definitively failed (including timeouts).
     */
    public function failed(?Throwable $exception): void
    {
        $this->campaign->markFailed($exception?->getMessage() ?: 'Discovery failed for an unknown reason.');

        Log::channel('discovery')->error('Campaign discovery failed.', $this->logContext($exception));
    }

    private function runsSynchronously(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(?Throwable $e): array
    {
        return [
            'campaign_id' => $this->campaign->getKey(),
            'provider' => $this->campaign->provider,
            'attempt' => $this->job ? $this->attempts() : null,
            'exception' => $e ? $e::class : null,
            'message' => $e?->getMessage(),
            'retryable' => $e instanceof DiscoveryException ? $e->retryable : null,
        ];
    }
}

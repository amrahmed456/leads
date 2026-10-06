<?php

namespace App\Jobs;

use App\Discovery\CampaignDiscoveryService;
use App\Discovery\Exceptions\DiscoveryException;
use App\Models\CampaignSourceRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one discovery source for one campaign.
 *
 * Transient failures (timeouts, rate limits, outages) are retried with
 * growing delays; permanent ones (rejected query, missing key, quota used
 * up) are not. Either way, a failing source only fails its own run: the
 * campaign completes with whatever the other sources found.
 */
class DiscoverCampaignFromProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds; queue retry_after must be larger (see config/queue.php). */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> Seconds to wait before the 2nd and 3rd attempts. */
    public array $retryDelays = [60, 300];

    public function __construct(public CampaignSourceRun $run)
    {
        $this->onQueue(config('discovery.queue'));
    }

    public function handle(CampaignDiscoveryService $discovery): void
    {
        if ($this->run->status->isFinished()) {
            return;
        }

        try {
            $discovery->runSource($this->run);
        } catch (Throwable $e) {
            $retryable = ! $e instanceof DiscoveryException || $e->retryable;

            if ($retryable && $this->attempts() < $this->tries && ! $this->runsSynchronously()) {
                $delay = $this->retryDelays[$this->attempts() - 1] ?? end($this->retryDelays);

                Log::channel('discovery')->warning('Source discovery attempt failed; will retry.', [
                    'campaign_id' => $this->run->search_campaign_id,
                    'provider' => $this->run->source,
                    'attempt' => $this->attempts(),
                    'exception' => $e::class,
                    'retry_in_seconds' => $delay,
                ]);

                $this->run->markRetrying($e->getMessage());
                $this->release($delay);

                return;
            }

            $discovery->failSource($this->run, $e->getMessage());
        }

        $discovery->finalize($this->run->campaign);
    }

    /**
     * Called when the job dies unexpectedly (timeout, crash after retries).
     */
    public function failed(?Throwable $exception): void
    {
        $discovery = app(CampaignDiscoveryService::class);

        if (! $this->run->status->isFinished()) {
            $discovery->failSource($this->run, $exception?->getMessage() ?: 'The source stopped unexpectedly.');
        }

        $discovery->finalize($this->run->campaign);
    }

    private function runsSynchronously(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }
}

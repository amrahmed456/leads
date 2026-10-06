<?php

namespace App\Jobs;

use App\Models\AtpSpider;
use App\Sources\AllThePlaces\AtpSpiderSynchronizer;
use App\Sources\QuotaExceededException;
use App\Support\DownloadException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs one AllThePlaces spider, then re-evaluates the businesses that
 * gained first-party branch evidence. Temporary download failures are
 * retried with a delay; anything else is recorded on the spider.
 */
class SyncAllThePlacesSpider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 280;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> */
    public array $retryDelays = [300, 1800];

    public function __construct(public AtpSpider $spider)
    {
        $this->onQueue(config('sources.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->spider->getKey();
    }

    public function handle(AtpSpiderSynchronizer $synchronizer): void
    {
        try {
            $summary = $synchronizer->sync($this->spider);
        } catch (DownloadException $e) {
            if ($e->retryable && $this->attempts() < $this->tries && ! $this->runsSynchronously()) {
                $this->release($this->retryDelays[$this->attempts() - 1] ?? 1800);
            }

            return;
        } catch (QuotaExceededException) {
            return;
        } catch (Throwable $e) {
            Log::channel('discovery')->warning('AllThePlaces spider sync failed.', ['spider' => $this->spider->spider, 'message' => $e->getMessage()]);

            return;
        }

        foreach (array_chunk($summary['business_ids'], 100) as $ids) {
            RecalculateLeadEligibility::dispatch($ids);
        }

        Log::channel('discovery')->info('AllThePlaces spider synced.', [
            'spider' => $this->spider->spider,
            ...array_diff_key($summary, ['business_ids' => true]),
        ]);
    }

    private function runsSynchronously(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }
}

<?php

namespace App\Jobs;

use App\Intelligence\BusinessEvaluator;
use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-evaluates a batch of businesses (identity, scores, eligibility), e.g.
 * after a rule change, a brand registry refresh or a spider sync. One
 * failing business never stops the batch.
 */
class RecalculateLeadEligibility implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 280;

    /**
     * @param  list<int>  $businessIds
     */
    public function __construct(public array $businessIds)
    {
        $this->onQueue(config('sources.queue'));
    }

    public function handle(BusinessEvaluator $evaluator): void
    {
        Business::query()->whereKey($this->businessIds)->get()->each(function (Business $business) use ($evaluator): void {
            try {
                $evaluator->evaluate($business);
            } catch (Throwable $e) {
                Log::channel('discovery')->error('Could not re-evaluate a business.', [
                    'business_id' => $business->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}

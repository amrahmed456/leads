<?php

namespace App\Jobs;

use App\Auditing\WebsiteAuditScheduler;
use App\Models\SearchCampaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After discovery: queue website audits (spread out over time) and queue the
 * identity analysis and lead qualification of every business in chunks, so
 * a large campaign never exceeds one job's time limit on shared hosting.
 * Safe to run again: active and fresh audits are never duplicated.
 */
class QueueCampaignAudits implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public SearchCampaign $campaign)
    {
        $this->onQueue(config('auditing.queue'));
    }

    public function handle(WebsiteAuditScheduler $scheduler): void
    {
        $summary = $scheduler->queueMany($this->campaign->businesses(), evaluate: false);

        $this->campaign->businesses()->select('businesses.id')->chunkById(100, function ($businesses): void {
            RecalculateLeadEligibility::dispatch($businesses->modelKeys());
        }, 'businesses.id', 'id');

        Log::channel('audits')->info('Website audits queued for campaign.', [
            'campaign_id' => $this->campaign->getKey(),
            ...$summary,
        ]);
    }
}

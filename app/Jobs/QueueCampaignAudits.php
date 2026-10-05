<?php

namespace App\Jobs;

use App\Auditing\WebsiteAuditScheduler;
use App\Models\SearchCampaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After discovery: evaluate businesses without a website and queue website
 * audits for the others, spread out over time. Safe to run again: active
 * and fresh audits are never duplicated.
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
        $summary = $scheduler->queueMany($this->campaign->businesses());

        Log::channel('audits')->info('Website audits queued for campaign.', [
            'campaign_id' => $this->campaign->getKey(),
            ...$summary,
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Jobs\RunCampaignDiscovery;
use App\Models\SearchCampaign;
use Illuminate\Console\Command;

/**
 * Runs discovery again for completed campaigns whose last run is old, so
 * every source observes its businesses again (fresh "last seen" dates,
 * closed places become stale). Spread out to stay within provider limits.
 */
class ReverifyCampaignsCommand extends Command
{
    protected $signature = 'campaigns:reverify
                            {--older-than=30 : Re-run campaigns last completed this many days ago}
                            {--limit=3 : Maximum campaigns per run}';

    protected $description = 'Re-run discovery for stale completed campaigns to refresh their evidence';

    public function handle(): int
    {
        $campaigns = SearchCampaign::query()
            ->where('status', CampaignStatus::Completed)
            ->where('completed_at', '<', now()->subDays(max(1, (int) $this->option('older-than'))))
            ->orderBy('completed_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($campaigns as $index => $campaign) {
            $campaign->markQueued();
            RunCampaignDiscovery::dispatch($campaign)->delay(now()->addMinutes($index * 10));
        }

        $this->info("Queued {$campaigns->count()} campaign(s) for re-verification.");

        return self::SUCCESS;
    }
}

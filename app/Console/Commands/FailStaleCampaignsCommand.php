<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\SearchCampaign;
use Illuminate\Console\Command;

/**
 * A discovery job is limited to a few minutes. A campaign still "running"
 * long after that lost its worker (e.g. the process was killed), so it is
 * marked failed and can be started again.
 */
class FailStaleCampaignsCommand extends Command
{
    protected $signature = 'campaigns:fail-stale {--minutes=60 : Minutes after which a running campaign is considered stale}';

    protected $description = 'Mark campaigns stuck in the running state as failed';

    public function handle(): int
    {
        $minutes = max(10, (int) $this->option('minutes'));

        $stale = SearchCampaign::query()
            ->where('status', CampaignStatus::Running)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stale as $campaign) {
            $campaign->markFailed("Discovery stopped responding after {$minutes} minutes (the queue worker may have stopped). Start it again.");
        }

        $this->info("Marked {$stale->count()} stale campaign(s) as failed.");

        return self::SUCCESS;
    }
}

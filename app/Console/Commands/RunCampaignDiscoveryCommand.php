<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Jobs\RunCampaignDiscovery;
use App\Models\SearchCampaign;
use Illuminate\Console\Command;

class RunCampaignDiscoveryCommand extends Command
{
    protected $signature = 'campaigns:discover
                            {campaign : The search campaign ID}
                            {--sync : Run in this process instead of queueing the job}';

    protected $description = 'Start business discovery for a search campaign';

    public function handle(): int
    {
        $campaign = SearchCampaign::find($this->argument('campaign'));

        if ($campaign === null) {
            $this->error('Search campaign not found.');

            return self::FAILURE;
        }

        if ($campaign->status === CampaignStatus::Running) {
            $this->error('Discovery is already running for this campaign.');

            return self::FAILURE;
        }

        $campaign->markQueued();

        if (! $this->option('sync')) {
            RunCampaignDiscovery::dispatch($campaign);

            $this->info("Discovery queued for campaign #{$campaign->id}. Make sure a worker is running: php artisan queue:work");

            return self::SUCCESS;
        }

        $this->info("Running discovery for \"{$campaign->name}\" via {$campaign->providerLabel()}...");

        RunCampaignDiscovery::dispatchSync($campaign);

        $campaign->refresh();

        if ($campaign->status !== CampaignStatus::Completed) {
            $this->error("Discovery failed: {$campaign->error_message}");

            return self::FAILURE;
        }

        $this->table(['Location', 'Businesses found', 'New', 'Skipped', 'Truncated'], [[
            $campaign->resolved_location ?? "{$campaign->latitude}, {$campaign->longitude}",
            $campaign->results_count,
            $campaign->new_businesses_count,
            $campaign->skipped_count,
            $campaign->results_truncated ? 'yes' : 'no',
        ]]);

        $this->table(['Source', 'Status', 'Found', 'New', 'Merged', 'Note'], $campaign->sourceRuns()->get()->map(fn ($run): array => [
            $run->sourceLabel(), $run->status->label(), $run->results_count, $run->created_count, $run->merged_count, $run->error_message ?? '',
        ])->all());

        return self::SUCCESS;
    }
}

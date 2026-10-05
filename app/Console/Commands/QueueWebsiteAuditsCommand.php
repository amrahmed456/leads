<?php

namespace App\Console\Commands;

use App\Auditing\WebsiteAuditScheduler;
use App\Models\Business;
use App\Models\SearchCampaign;
use Illuminate\Console\Command;

class QueueWebsiteAuditsCommand extends Command
{
    protected $signature = 'audits:queue
                            {--campaign= : Audit the businesses found by this search campaign}
                            {--business=* : Audit these business IDs}
                            {--all : Audit every business}
                            {--force : Audit again even when a recent audit exists}
                            {--sync : Run the audits now in this process instead of queueing them}';

    protected $description = 'Queue website audits; businesses without a website are evaluated directly';

    public function handle(WebsiteAuditScheduler $scheduler): int
    {
        $businesses = match (true) {
            $this->option('campaign') !== null => SearchCampaign::find($this->option('campaign'))?->businesses(),
            $this->option('business') !== [] => Business::query()->whereKey($this->option('business')),
            (bool) $this->option('all') => Business::query(),
            default => null,
        };

        if ($businesses === null) {
            $this->error('Choose the businesses with --campaign=ID, --business=ID or --all (campaign IDs must exist).');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            $this->info('Auditing now: one homepage request per website, spaced out to respect the rate limit...');
        }

        $summary = $scheduler->queueMany($businesses, (bool) $this->option('force'), (bool) $this->option('sync'));

        $this->table(
            [$this->option('sync') ? 'Audited' : 'Queued', 'Without website', 'Skipped (recent or in progress)', 'Failed'],
            [array_values($summary)],
        );

        if (! $this->option('sync') && $summary['queued'] > 0) {
            $this->line('Audits run in the background. Make sure the queue is being processed (php artisan queue:work, or the scheduler on cPanel).');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllThePlacesSpider;
use App\Models\AtpSpider;
use App\Sources\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Syncs the enabled AllThePlaces spiders (only those configured by the
 * admin, never the global archive). Spiders synced recently are skipped.
 */
class SyncSourcesCommand extends Command
{
    protected $signature = 'sources:sync
                            {--spider=* : Only these spiders}
                            {--force : Sync even when synced in the last 6 days}
                            {--sync : Run in this process instead of queueing}';

    protected $description = 'Sync AllThePlaces brand spiders into the local first-party branch index';

    public function handle(SourceRegistry $sources): int
    {
        if (! $sources->isAvailable('alltheplaces')) {
            $this->warn('AllThePlaces is disabled in Settings → Data Sources.');

            return self::SUCCESS;
        }

        $spiders = AtpSpider::query()
            ->where('enabled', true)
            ->when($this->option('spider') !== [], fn ($query) => $query->whereIn('spider', $this->option('spider')))
            ->when(! $this->option('force'), fn ($query) => $query->where(fn ($stale) => $stale->whereNull('last_success_at')->orWhere('last_success_at', '<', now()->subDays(6))))
            ->get();

        foreach ($spiders as $spider) {
            $this->option('sync') ? SyncAllThePlacesSpider::dispatchSync($spider) : SyncAllThePlacesSpider::dispatch($spider);
        }

        if ($this->option('sync')) {
            $this->table(['Spider', 'Features', 'Stored', 'Matched', 'Last error'], $spiders->map(fn (AtpSpider $spider): array => [
                $spider->spider, $spider->refresh()->feature_count, $spider->stored_count, $spider->matched_count, $spider->last_error ?? '—',
            ])->all());
        } else {
            $this->info("Queued {$spiders->count()} spider sync(s).");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Shows every data source with its status, health, quota use and last
 * error, and stores the computed health for the settings page.
 */
class ProvidersHealthCommand extends Command
{
    protected $signature = 'providers:health';

    protected $description = 'Show the health, usage and quota of every data source';

    public function handle(SourceRegistry $sources, QuotaManager $quotas): int
    {
        $rows = [];

        foreach ($sources->all() as $key => $source) {
            $health = $sources->health($key, $quotas);
            $state = $sources->state($key);

            if ($source->implemented) {
                $state->forceFill(['health_status' => $health, 'last_checked_at' => now()])->save();
            }

            $limit = $quotas->dailyLimit($key);

            $rows[] = [
                $source->label,
                $source->isFree() ? 'free' : 'paid',
                $source->independenceGroup,
                $sources->isEnabled($key) ? 'yes' : 'no',
                $health->label(),
                $quotas->used($key).($limit !== null ? " / {$limit}" : '').' '.$source->quotaUnit(),
                $state->last_success_at?->diffForHumans() ?? '—',
                mb_strimwidth((string) $state->last_error, 0, 60, '…') ?: '—',
            ];
        }

        $this->table(['Source', 'Tier', 'Independence group', 'Enabled', 'Health', 'Used today', 'Last success', 'Last error'], $rows);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Intelligence\BusinessEvaluator;
use App\Jobs\RecalculateLeadEligibility;
use App\Models\Business;
use App\Models\LeadScore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-evaluates identity, scores and lead eligibility, e.g. after changing
 * rules in config/intelligence.php or refreshing the brand registry.
 */
class RecalculateEligibilityCommand extends Command
{
    protected $signature = 'businesses:recalculate-eligibility
                            {--campaign= : Only businesses found by this campaign}
                            {--business=* : Only these business IDs}
                            {--outdated : Only businesses scored with older rules or never scored}
                            {--sync : Run in this process instead of queueing}';

    protected $description = 'Recalculate entity classification, scores and lead eligibility';

    public function handle(BusinessEvaluator $evaluator): int
    {
        $query = Business::query()
            ->when($this->option('campaign') !== null, fn ($query) => $query->whereHas('campaigns', fn ($campaigns) => $campaigns->whereKey((int) $this->option('campaign'))))
            ->when($this->option('business') !== [], fn ($query) => $query->whereKey($this->option('business')))
            ->when($this->option('outdated'), fn ($query) => $query->whereDoesntHave('leadScore', fn ($score) => $score->where('version', (string) config('intelligence.lead_score.version'))));

        $total = 0;
        $failed = 0;

        $query->chunkById(100, function ($businesses) use ($evaluator, &$total, &$failed): void {
            if (! $this->option('sync')) {
                RecalculateLeadEligibility::dispatch($businesses->modelKeys());
                $total += $businesses->count();

                return;
            }

            foreach ($businesses as $business) {
                try {
                    $evaluator->evaluate($business);
                    $total++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("Business #{$business->id}: {$e->getMessage()}");
                }
            }
        });

        if (! $this->option('sync')) {
            $this->info("Queued {$total} business(es) for re-evaluation.");

            return self::SUCCESS;
        }

        $this->info("Re-evaluated {$total} business(es)".($failed > 0 ? ", {$failed} failed" : '').'.');
        $this->table(['Status', 'Businesses'], LeadScore::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn (mixed $count, string $status): array => [$status, $count])->values()->all());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Discovery;

use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\Exceptions\DiscoveryException;
use App\Discovery\Exceptions\SourceNotApplicableException;
use App\Enums\CampaignStatus;
use App\Enums\SourceRunStatus;
use App\Jobs\QueueCampaignAudits;
use App\Models\CampaignSourceRun;
use App\Models\SearchCampaign;
use App\Sources\SecretScrubber;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs multi-source discovery for a search campaign:
 *
 *   locate the search area → one run per discovery source → each source:
 *   query → normalize → store without duplicates → link to the campaign →
 *   when every source has finished, complete the campaign.
 *
 * Sources are independent: one failing (outage, quota, missing key) never
 * discards what the others found. The campaign fails only when every
 * source failed.
 */
final class CampaignDiscoveryService
{
    public function __construct(
        private readonly DiscoveryProviderRegistry $providers,
        private readonly SourceRegistry $sources,
        private readonly GeocoderInterface $geocoder,
        private readonly BusinessNormalizer $normalizer,
        private readonly BusinessRecorder $recorder,
    ) {}

    /**
     * Locate the campaign and prepare one run per discovery source.
     * Sources that are unavailable are skipped with the reason.
     *
     * @return list<CampaignSourceRun> Runs to execute
     *
     * @throws DiscoveryException when the search area cannot be located
     */
    public function start(SearchCampaign $campaign): array
    {
        $campaign->markRunning();

        $this->ensureLocation($campaign);

        $keys = $campaign->discoverySources();
        $campaign->sourceRuns()->whereNotIn('source', $keys)->delete();

        $runnable = [];

        foreach ($keys as $key) {
            $run = $campaign->sourceRuns()->firstOrCreate(['source' => $key]);
            $run->reset();

            if (! $this->providers->isEnabled($key)) {
                $run->markSkipped($this->providers->unavailableReason($key));

                continue;
            }

            $runnable[] = $run;
        }

        if ($runnable === []) {
            $this->finalize($campaign);
        }

        return $runnable;
    }

    /**
     * Query one source and store its results.
     *
     * @throws DiscoveryException when the source cannot be queried
     */
    public function runSource(CampaignSourceRun $run): void
    {
        $run->loadMissing('campaign');
        $campaign = $run->campaign;
        $run->markRunning();

        $provider = $this->providers->get($run->source);

        try {
            $result = $provider->discover($campaign->toDiscoveryQuery());
        } catch (SourceNotApplicableException $e) {
            $run->markSkipped($e->getMessage());

            return;
        } catch (DiscoveryException $e) {
            $this->sources->recordFailure($run->source, $e->getMessage());

            throw $e;
        }

        $this->sources->recordSuccess($run->source);

        $businessIds = [];
        $created = 0;
        $merged = 0;
        $skipped = $result->skipped;

        foreach ($result->businesses as $discovered) {
            $normalized = $this->normalizer->normalize($discovered);

            if ($normalized === null) {
                $skipped++;

                continue;
            }

            $recorded = $this->recorder->record($normalized, $campaign->city);
            $businessIds[$recorded->business->getKey()] = true;

            match ($recorded->outcome) {
                RecordOutcome::Created => $created++,
                RecordOutcome::Merged => $merged++,
                RecordOutcome::Updated => null,
            };
        }

        foreach (array_chunk(array_keys($businessIds), 500) as $chunk) {
            $campaign->businesses()->syncWithoutDetaching($chunk);
        }

        $run->markCompleted(count($businessIds), $created, $merged, $skipped, $result->truncated);

        Log::channel('discovery')->info('Campaign source discovery completed.', [
            'campaign_id' => $campaign->getKey(),
            'provider' => $provider->key(),
            'received' => count($result->businesses) + $result->skipped,
            'businesses' => count($businessIds),
            'created' => $created,
            'merged' => $merged,
            'skipped' => $skipped,
            'truncated' => $result->truncated,
        ]);
    }

    /**
     * Record a source's definitive failure. The campaign continues with the other sources.
     */
    public function failSource(CampaignSourceRun $run, string $reason): void
    {
        $run->markFailed(SecretScrubber::scrub($reason, $this->sources->has($run->source) ? $this->sources->secrets($run->source) : []));

        Log::channel('discovery')->warning('Campaign source discovery failed.', [
            'campaign_id' => $run->search_campaign_id,
            'provider' => $run->source,
            'message' => $run->error_message,
        ]);
    }

    /**
     * Complete the campaign once every source run has finished. Safe to call
     * from every source job: only the call that sees all runs finished
     * completes it, once.
     *
     * @return bool Whether the campaign was completed or failed by this call
     */
    public function finalize(SearchCampaign $campaign): bool
    {
        $finalized = DB::transaction(function () use ($campaign): ?CampaignStatus {
            $locked = SearchCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CampaignStatus::Running) {
                return null;
            }

            $runs = $locked->sourceRuns()->get();

            if ($runs->contains(fn (CampaignSourceRun $run): bool => ! $run->status->isFinished())) {
                return null;
            }

            $completed = $runs->where('status', SourceRunStatus::Completed);
            $problems = $runs->whereIn('status', [SourceRunStatus::Failed, SourceRunStatus::Skipped])
                ->map(fn (CampaignSourceRun $run): string => "{$run->sourceLabel()}: {$run->error_message}")
                ->values()
                ->all();

            if ($completed->isEmpty()) {
                $locked->markFailed($problems === [] ? 'No discovery source is selected.' : implode(' ', $problems));

                return CampaignStatus::Failed;
            }

            $locked->markCompleted(
                results: $locked->businesses()->count(),
                newBusinesses: (int) $completed->sum('created_count'),
                skipped: (int) $completed->sum('skipped_count'),
                truncated: $completed->contains('truncated', true),
                warning: $problems === [] ? null : 'Some sources did not run: '.implode(' ', $problems),
            );

            return CampaignStatus::Completed;
        });

        if ($finalized === null) {
            return false;
        }

        $campaign->refresh();

        if ($finalized === CampaignStatus::Failed) {
            Log::channel('discovery')->error('Campaign discovery failed.', [
                'campaign_id' => $campaign->getKey(),
                'sources' => $campaign->discoverySources(),
                'message' => $campaign->error_message,
            ]);

            return true;
        }

        Log::channel('discovery')->info('Campaign discovery completed.', [
            'campaign_id' => $campaign->getKey(),
            'sources' => $campaign->discoverySources(),
            'businesses' => $campaign->results_count,
            'created' => $campaign->new_businesses_count,
            'skipped' => $campaign->skipped_count,
            'truncated' => $campaign->results_truncated,
        ]);

        // Follow-up work must never turn a completed discovery into a failure.
        rescue(fn () => QueueCampaignAudits::dispatch($campaign));

        return true;
    }

    private function ensureLocation(SearchCampaign $campaign): void
    {
        if ($campaign->hasCoordinates()) {
            return;
        }

        $location = $this->geocoder->geocode($campaign->city, $campaign->country_code);

        if ($location === null) {
            throw DiscoveryException::permanent(sprintf(
                'Could not find "%s" in %s. Check the spelling or enter the coordinates manually.',
                $campaign->city,
                $campaign->country_code->label(),
            ));
        }

        $campaign->useLocation($location);
    }
}

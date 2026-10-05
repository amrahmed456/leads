<?php

namespace App\Discovery;

use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\Exceptions\DiscoveryException;
use App\Models\SearchCampaign;
use Illuminate\Support\Facades\Log;

/**
 * Runs discovery for a search campaign:
 *
 *   locate the search area → query the campaign's provider → normalize each
 *   record → store it without duplicates → link it to the campaign.
 */
final class CampaignDiscoveryService
{
    public function __construct(
        private readonly DiscoveryProviderRegistry $providers,
        private readonly GeocoderInterface $geocoder,
        private readonly BusinessNormalizer $normalizer,
        private readonly BusinessRecorder $recorder,
    ) {}

    /**
     * @throws DiscoveryException
     */
    public function run(SearchCampaign $campaign): void
    {
        $campaign->markRunning();

        $this->ensureLocation($campaign);

        $provider = $this->providers->get($campaign->provider);
        $result = $provider->discover($campaign->toDiscoveryQuery());

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

        $campaign->markCompleted(
            results: count($businessIds),
            newBusinesses: $created,
            skipped: $skipped,
            truncated: $result->truncated,
        );

        Log::channel('discovery')->info('Campaign discovery completed.', [
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

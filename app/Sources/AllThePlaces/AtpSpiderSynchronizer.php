<?php

namespace App\Sources\AllThePlaces;

use App\Enums\Country;
use App\Identity\Brands\BrandRegistry;
use App\Identity\SourcePlaceLinker;
use App\Models\AtpSpider;
use App\Models\SourcePlace;
use App\Sources\QuotaManager;
use App\Sources\SecretScrubber;
use App\Sources\SourceRegistry;
use App\Support\BoundedDownloader;
use App\Support\Streaming\GeoJsonFeatureReader;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Syncs one AllThePlaces spider: downloads only that spider's GeoJSON from
 * the latest run, streams it into the local index (keeping our countries),
 * records the brand's branch count and countries, and links the branches
 * to matching businesses as first-party evidence.
 */
final class AtpSpiderSynchronizer
{
    private const BATCH_SIZE = 500;

    /**
     * @param  list<string>  $countries  Upper-case ISO codes to keep (empty = campaign countries)
     */
    public function __construct(
        private readonly AllThePlacesClient $client,
        private readonly BoundedDownloader $downloader,
        private readonly AtpFeatureMapper $mapper,
        private readonly BrandRegistry $brands,
        private readonly SourcePlaceLinker $linker,
        private readonly QuotaManager $quotas,
        private readonly SourceRegistry $sources,
        private readonly array $countries,
        private readonly int $maxBytes,
    ) {}

    /**
     * @return array{features: int, stored: int, removed: int, matched: int, countries: list<string>, business_ids: list<int>}
     *
     * @throws Throwable when the spider cannot be downloaded or read; recorded on the spider first
     */
    public function sync(AtpSpider $spider): array
    {
        $spider->forceFill(['last_synced_at' => now()])->save();
        $path = Storage::disk('local')->path("imports/atp/{$spider->spider}.geojson");

        try {
            $this->quotas->consume('alltheplaces');
            $run = $this->client->latestRun();
            $url = $this->client->spiderUrl($run, $spider->spider);
            $this->downloader->download($url, $path, $this->maxBytes, timeoutSeconds: 180);

            $summary = $this->import($spider, $path);
        } catch (Throwable $e) {
            $message = SecretScrubber::scrub($e->getMessage());
            $spider->forceFill(['last_error' => mb_substr($message, 0, 1000)])->save();
            $this->sources->recordFailure('alltheplaces', "{$spider->spider}: {$message}");

            throw $e;
        } finally {
            @unlink($path);
        }

        $spider->forceFill([
            'last_run_id' => $run['run_id'],
            'output_url' => $url,
            'last_success_at' => now(),
            'feature_count' => $summary['features'],
            'stored_count' => $summary['stored'],
            'matched_count' => $summary['matched'],
            'last_error' => null,
            'last_result' => array_diff_key($summary, ['business_ids' => true]),
        ])->save();

        $this->sources->recordSuccess('alltheplaces');

        return $summary;
    }

    /**
     * @return array{features: int, stored: int, removed: int, matched: int, countries: list<string>, business_ids: list<int>}
     */
    public function import(AtpSpider $spider, string $path): array
    {
        $startedAt = now()->subSecond();
        $keep = $this->countriesToKeep($spider);
        $features = 0;
        $branches = 0;
        $countries = [];
        $wikidata = [];
        $rows = [];
        $stored = 0;

        foreach ((new GeoJsonFeatureReader)->read($path) as [$feature]) {
            $features++;
            $row = $feature === null ? null : $this->mapper->map($feature, $spider);

            if ($row === null) {
                continue;
            }

            $branches++;

            if ($row['country_code'] !== null) {
                $countries[$row['country_code']] = true;
            }

            if ($row['brand_wikidata'] !== null) {
                $wikidata[$row['brand_wikidata']] = ($wikidata[$row['brand_wikidata']] ?? 0) + 1;
            }

            // Branches abroad still count towards the brand's size; only ours are stored.
            if ($row['country_code'] === null ? $spider->country_code === null : ! in_array($row['country_code'], $keep, true)) {
                continue;
            }

            $row['country_code'] ??= $spider->country_code;
            $rows[] = $row;

            if (count($rows) >= self::BATCH_SIZE) {
                $stored += SourcePlace::upsertRows($rows, SourcePlace::DATASET_ATP_SPIDER, $spider->getKey());
                $rows = [];
            }
        }

        $stored += SourcePlace::upsertRows($rows, SourcePlace::DATASET_ATP_SPIDER, $spider->getKey());

        // Branches no longer listed by the store locator are gone.
        $removed = SourcePlace::query()
            ->where('dataset_type', SourcePlace::DATASET_ATP_SPIDER)
            ->where('dataset_id', $spider->getKey())
            ->where('observed_at', '<', $startedAt)
            ->delete();

        arsort($wikidata);
        $brandWikidata = $spider->brand_wikidata ?? array_key_first($wikidata);
        $brand = $this->brands->resolve($spider->brand_name, $brandWikidata, 'alltheplaces', ['metadata' => ['atp_spider' => $spider->spider]]);
        $this->brands->recordLocations($brand, $branches, array_keys($countries));

        $businessIds = [];
        $spider->places()->chunkById(200, function ($places) use (&$businessIds): void {
            array_push($businessIds, ...$this->linker->linkPlaces($places));
        });

        return [
            'features' => $features,
            'stored' => $stored,
            'removed' => $removed,
            'matched' => count($businessIds),
            'countries' => array_keys($countries),
            'business_ids' => $businessIds,
        ];
    }

    /**
     * @return list<string>
     */
    private function countriesToKeep(AtpSpider $spider): array
    {
        if ($spider->country_code !== null) {
            return [$spider->country_code];
        }

        return $this->countries !== []
            ? array_map('strtoupper', $this->countries)
            : array_map(fn (Country $country): string => $country->value, Country::cases());
    }
}

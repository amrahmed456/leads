<?php

namespace App\Discovery;

use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\Data\RecordedBusiness;
use App\Identity\EntityMatcher;
use App\Identity\Evidence\EvidenceProvenance;
use App\Identity\Evidence\EvidenceRecorder;
use App\Identity\IndependenceResolver;
use App\Identity\MatchCandidate;
use App\Identity\MatchResult;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Sources\SourceRegistry;
use App\Support\Geo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores normalized provider records without creating duplicates.
 *
 * 1. A provider record (provider + provider_id) is stored at most once; this
 *    is enforced by a unique index. Seeing it again refreshes its business.
 * 2. A record carrying a strong identifier of a stored record (a Geoapify
 *    result with its OpenStreetMap ID) joins that record's business.
 * 3. A new record is linked to an existing nearby business when the entity
 *    matcher says they are the same place (see EntityMatcher).
 * 4. Only otherwise is a new business created.
 *
 * Every source record keeps its role, independence group, confidence and
 * provenance, and every fact it states is recorded as evidence.
 */
final class BusinessRecorder
{
    private const DATA_ATTRIBUTES = [
        'name' => 'name',
        'alternate_name' => 'alternateName',
        'category' => 'category',
        'type' => 'type',
        'country_code' => 'countryCode',
        'address' => 'address',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'phone' => 'phone',
        'website' => 'website',
        'email' => 'email',
        'opening_hours' => 'openingHours',
        'operating_status' => 'operatingStatus',
        'rating' => 'rating',
        'reviews_count' => 'reviewsCount',
    ];

    public function __construct(
        private readonly EntityMatcher $matcher,
        private readonly EvidenceRecorder $evidence,
        private readonly IndependenceResolver $independence,
        private readonly SourceRegistry $sources,
    ) {}

    /**
     * @param  string|null  $city  City searched for; set on businesses that do not have one yet
     */
    public function record(DiscoveredBusiness $data, ?string $city = null): RecordedBusiness
    {
        try {
            return DB::transaction(fn () => $this->store($data, $city));
        } catch (UniqueConstraintViolationException) {
            // A concurrent run stored this provider record first; it exists now, so update it.
            return DB::transaction(fn () => $this->store($data, $city));
        }
    }

    /**
     * Link a record to a business already known to be the same place (e.g. a
     * first-party store locator branch matched during identity analysis).
     */
    public function attach(Business $business, DiscoveredBusiness $data, MatchResult $match): BusinessSource
    {
        return DB::transaction(function () use ($business, $data, $match): BusinessSource {
            $this->apply($business, $data, overwrite: false, city: null);
            $business->save();

            $source = $this->createSource($business, $data, isPrimary: false, matchReasons: $match->reasons);
            $this->evidence->recordDiscovered($business, EvidenceProvenance::fromSource($source), $data);

            return $source;
        });
    }

    private function store(DiscoveredBusiness $data, ?string $city): RecordedBusiness
    {
        $now = now();

        $source = BusinessSource::query()
            ->with('business')
            ->where('provider', $data->provider)
            ->where('provider_id', $data->providerId)
            ->first();

        if ($source !== null) {
            $business = $source->business;
            $this->apply($business, $data, overwrite: $source->is_primary, city: $city);
            $business->last_seen_at = $now;
            $business->save();

            $source->forceFill([
                'source_url' => $data->sourceUrl ?? $source->source_url,
                'raw_data' => $data->raw ?? $source->raw_data,
                'confidence' => $data->confidence ?? $source->confidence,
                'provenance' => $data->provenance !== [] ? $data->provenance : $source->provenance,
                'last_seen_at' => $now,
            ])->save();

            $this->evidence->recordDiscovered($business, EvidenceProvenance::fromSource($source), $data);

            return new RecordedBusiness($business, RecordOutcome::Updated);
        }

        [$business, $match] = $this->findDuplicate($data);
        $outcome = $business === null ? RecordOutcome::Created : RecordOutcome::Merged;

        $business ??= new Business(['first_discovered_at' => $now]);
        $this->apply($business, $data, overwrite: $outcome === RecordOutcome::Created, city: $city);
        $business->last_seen_at = $now;
        $business->save();

        $source = $this->createSource($business, $data, isPrimary: $outcome === RecordOutcome::Created, matchReasons: $match?->reasons);
        $this->evidence->recordDiscovered($business, EvidenceProvenance::fromSource($source), $data);

        return new RecordedBusiness($business, $outcome);
    }

    /**
     * @param  list<string>|null  $matchReasons
     */
    private function createSource(Business $business, DiscoveredBusiness $data, bool $isPrimary, ?array $matchReasons): BusinessSource
    {
        $now = now();

        return $business->sources()->create([
            'provider' => $data->provider,
            'source_role' => $this->sources->primaryRole($data->provider),
            'independence_group' => $data->independenceGroup ?? $this->independence->groupFor($data->provider, $data->provenance),
            'provider_id' => $data->providerId,
            'is_primary' => $isPrimary,
            'confidence' => $data->confidence,
            'provenance' => $data->provenance === [] ? null : $data->provenance,
            'match_reasons' => $matchReasons,
            'source_url' => $data->sourceUrl,
            'raw_data' => $data->raw,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Copy record values onto the business. The business's original source
     * may overwrite values (it reflects edits at the source); other sources
     * only fill in what is missing. A missing value never erases known data.
     */
    private function apply(Business $business, DiscoveredBusiness $data, bool $overwrite, ?string $city): void
    {
        foreach (self::DATA_ATTRIBUTES as $attribute => $property) {
            $value = $data->{$property};

            if ($value !== null && ($overwrite || $business->getAttribute($attribute) === null)) {
                $business->setAttribute($attribute, $value);
            }
        }

        if ($business->city === null && $city !== null) {
            $business->city = $city;
        }

        $existingLinks = $business->social_links ?? [];
        $links = $overwrite ? array_merge($existingLinks, $data->socialLinks) : $existingLinks + $data->socialLinks;
        $business->social_links = $links === [] ? null : $links;
    }

    /**
     * @return array{0: ?Business, 1: ?MatchResult}
     */
    private function findDuplicate(DiscoveredBusiness $data): array
    {
        $candidate = MatchCandidate::fromDiscovered($data);

        // A strong identifier (e.g. the OpenStreetMap ID inside a Geoapify result) wins.
        foreach ($data->identifiers as $provider => $id) {
            $linked = BusinessSource::query()->with('business')->where('provider', $provider)->where('provider_id', $id)->first();

            if ($linked !== null) {
                return [$linked->business, new MatchResult(true, 100, ["Same {$provider} record ({$id})"])];
            }
        }

        if ($data->latitude === null || $data->longitude === null) {
            return [null, null];
        }

        [$latDelta, $lonDelta] = Geo::boundingDeltas($data->latitude, $this->matcher->maxDistance());

        $best = null;

        Business::query()
            ->with(['sources', 'brand'])
            ->whereBetween('latitude', [$data->latitude - $latDelta, $data->latitude + $latDelta])
            ->whereBetween('longitude', [$data->longitude - $lonDelta, $data->longitude + $lonDelta])
            ->limit(200)
            ->get()
            ->each(function (Business $business) use ($candidate, &$best): void {
                $match = $this->matcher->match(MatchCandidate::fromBusiness($business), $candidate);

                if ($match->matched && ($best === null || $this->isBetter($match, $best[1]))) {
                    $best = [$business, $match];
                }
            });

        return $best ?? [null, null];
    }

    private function isBetter(MatchResult $match, MatchResult $current): bool
    {
        return $match->confidence > $current->confidence
            || ($match->confidence === $current->confidence && ($match->distanceMeters ?? INF) < ($current->distanceMeters ?? INF));
    }
}

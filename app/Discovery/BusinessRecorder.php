<?php

namespace App\Discovery;

use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\Data\RecordedBusiness;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Support\Geo;
use App\Support\Normalization\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores normalized provider records without creating duplicates.
 *
 * 1. A provider record (provider + provider_id) is stored at most once; this
 *    is enforced by a unique index. Seeing it again refreshes its business.
 * 2. A new provider record is linked to an existing business with the same
 *    normalized name nearby (OSM often maps one shop as both a point and a
 *    building; later, other providers will report the same business).
 * 3. Only otherwise is a new business created.
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
        'rating' => 'rating',
        'reviews_count' => 'reviewsCount',
    ];

    public function __construct(private readonly int $dedupeDistanceMeters) {}

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
                'last_seen_at' => $now,
            ])->save();

            return new RecordedBusiness($business, RecordOutcome::Updated);
        }

        $business = $this->findDuplicate($data);
        $outcome = $business === null ? RecordOutcome::Created : RecordOutcome::Merged;

        $business ??= new Business(['first_discovered_at' => $now]);
        $this->apply($business, $data, overwrite: $outcome === RecordOutcome::Created, city: $city);
        $business->last_seen_at = $now;
        $business->save();

        $business->sources()->create([
            'provider' => $data->provider,
            'provider_id' => $data->providerId,
            'is_primary' => $outcome === RecordOutcome::Created,
            'source_url' => $data->sourceUrl,
            'raw_data' => $data->raw,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        return new RecordedBusiness($business, $outcome);
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

    private function findDuplicate(DiscoveredBusiness $data): ?Business
    {
        if ($data->latitude === null || $data->longitude === null) {
            return null;
        }

        [$latDelta, $lonDelta] = Geo::boundingDeltas($data->latitude, $this->dedupeDistanceMeters);

        return Business::query()
            ->where('name_normalized', Text::matchKey($data->name))
            ->whereBetween('latitude', [$data->latitude - $latDelta, $data->latitude + $latDelta])
            ->whereBetween('longitude', [$data->longitude - $lonDelta, $data->longitude + $lonDelta])
            ->get()
            ->map(fn (Business $business) => [
                'business' => $business,
                'distance' => Geo::distanceMeters($data->latitude, $data->longitude, $business->latitude, $business->longitude),
            ])
            ->filter(fn (array $candidate) => $candidate['distance'] <= $this->dedupeDistanceMeters)
            ->sortBy('distance')
            ->first()['business'] ?? null;
    }
}

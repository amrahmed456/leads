<?php

namespace App\Discovery\Providers\Geoapify;

use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\Data\DiscoveryQuery;
use App\Enums\BusinessCategory;

/**
 * Converts Geoapify Places features into DiscoveredBusiness objects. The
 * OpenStreetMap ID in the datasource is kept as a strong identifier, so
 * the same place found through OSM and Geoapify becomes one business.
 */
final class GeoapifyFeatureMapper
{
    private const OSM_TYPES = ['n' => 'node', 'w' => 'way', 'r' => 'relation', 'node' => 'node', 'way' => 'way', 'relation' => 'relation'];

    /**
     * @param  array<string, list<string>>  $categories  BusinessCategory value => Geoapify categories
     */
    public function __construct(private readonly array $categories) {}

    /**
     * @param  array<mixed>  $feature
     */
    public function map(array $feature, DiscoveryQuery $query): ?DiscoveredBusiness
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $name = $this->string($properties['name'] ?? null);
        $placeId = $this->string($properties['place_id'] ?? null);
        [$latitude, $longitude] = $this->coordinates($feature, $properties);

        if ($name === null || $placeId === null || $latitude === null) {
            return null;
        }

        $raw = is_array($properties['datasource']['raw'] ?? null) ? $properties['datasource']['raw'] : [];
        $osmId = $this->osmId($properties['datasource']['sourcename'] ?? null, $raw);
        $contact = is_array($properties['contact'] ?? null) ? $properties['contact'] : [];
        $international = is_array($properties['name_international'] ?? null) ? $properties['name_international'] : [];

        return new DiscoveredBusiness(
            provider: GeoapifyProvider::KEY,
            providerId: $placeId,
            name: $name,
            alternateName: $this->string($international['ar'] ?? $international['en'] ?? null),
            category: $this->category($properties['categories'] ?? []) ?? $query->category,
            type: $this->string($raw['amenity'] ?? $raw['shop'] ?? $raw['office'] ?? $raw['healthcare'] ?? null),
            countryCode: is_string($properties['country_code'] ?? null) ? strtoupper($properties['country_code']) : $query->country->value,
            address: $this->string($properties['formatted'] ?? $properties['address_line2'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            phone: $this->string($contact['phone'] ?? $raw['phone'] ?? null),
            website: $this->string($properties['website'] ?? $raw['website'] ?? null),
            email: $this->string($contact['email'] ?? $raw['email'] ?? null),
            openingHours: $this->string($properties['opening_hours'] ?? null),
            socialLinks: array_filter([
                'facebook' => $this->string($raw['contact:facebook'] ?? null),
                'instagram' => $this->string($raw['contact:instagram'] ?? null),
            ]),
            sourceUrl: null,
            // Only identifiers and the OSM tags behind the result are kept.
            raw: ['place_id' => $placeId, 'categories' => $properties['categories'] ?? [], 'osm' => $osmId],
            brand: $this->string($properties['brand'] ?? $raw['brand'] ?? null),
            brandWikidata: $this->string($properties['brand_details']['wikidata'] ?? $raw['brand:wikidata'] ?? null),
            operator: $this->string($raw['operator'] ?? null),
            identifiers: $osmId !== null ? ['openstreetmap' => $osmId] : [],
        );
    }

    /**
     * @param  array<mixed>  $feature
     * @param  array<mixed>  $properties
     * @return array{0: ?float, 1: ?float}
     */
    private function coordinates(array $feature, array $properties): array
    {
        $latitude = $properties['lat'] ?? ($feature['geometry']['coordinates'][1] ?? null);
        $longitude = $properties['lon'] ?? ($feature['geometry']['coordinates'][0] ?? null);

        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function osmId(mixed $sourceName, array $raw): ?string
    {
        $type = self::OSM_TYPES[$raw['osm_type'] ?? ''] ?? null;
        $id = $raw['osm_id'] ?? null;

        if ($sourceName !== 'openstreetmap' || $type === null || ! is_numeric($id)) {
            return null;
        }

        return $type.'/'.abs((int) $id);
    }

    private function category(mixed $categories): ?BusinessCategory
    {
        if (! is_array($categories)) {
            return null;
        }

        foreach ($this->categories as $category => $geoapifyCategories) {
            if (array_intersect($geoapifyCategories, $categories) !== []) {
                return BusinessCategory::tryFrom($category);
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) ? (string) $value : null);
    }
}

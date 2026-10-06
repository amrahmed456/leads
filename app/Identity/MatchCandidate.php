<?php

namespace App\Identity;

use App\Discovery\Data\DiscoveredBusiness;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\SourcePlace;

/**
 * The identifying attributes of a record or business, in one comparable shape.
 */
final readonly class MatchCandidate
{
    /**
     * @param  list<string>  $names
     * @param  array<string, list<string>>  $identifiers  source key => record IDs
     */
    public function __construct(
        public array $names,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $brand = null,
        public ?string $brandWikidata = null,
        public array $identifiers = [],
    ) {}

    public static function fromDiscovered(DiscoveredBusiness $data): self
    {
        $identifiers = [$data->provider => [$data->providerId]];

        foreach ($data->identifiers as $source => $id) {
            $identifiers[$source][] = $id;
        }

        return new self(
            names: array_values(array_filter([$data->name, $data->alternateName])),
            latitude: $data->latitude,
            longitude: $data->longitude,
            phone: $data->phone,
            website: $data->website,
            brand: $data->brand,
            brandWikidata: $data->brandWikidata,
            identifiers: $identifiers,
        );
    }

    public static function fromPlace(SourcePlace $place): self
    {
        return new self(
            names: array_values(array_filter([$place->name, $place->branch !== null ? "{$place->brand_name} {$place->branch}" : null])),
            latitude: $place->latitude,
            longitude: $place->longitude,
            phone: $place->phone,
            website: $place->website,
            brand: $place->brand_name,
            brandWikidata: $place->brand_wikidata,
            identifiers: [$place->source => [$place->source_record_id]],
        );
    }

    /**
     * The business side. Uses the sources and brand relations only when already loaded.
     */
    public static function fromBusiness(Business $business): self
    {
        $identifiers = [];

        if ($business->relationLoaded('sources')) {
            foreach ($business->sources as $source) {
                /** @var BusinessSource $source */
                $identifiers[$source->provider][] = $source->provider_id;
            }
        }

        $brand = $business->relationLoaded('brand') ? $business->brand : null;

        return new self(
            names: array_values(array_filter([$business->name, $business->alternate_name])),
            latitude: $business->latitude,
            longitude: $business->longitude,
            phone: $business->phone,
            website: $business->website,
            brand: $brand?->name,
            brandWikidata: $brand?->wikidata_id,
            identifiers: $identifiers,
        );
    }
}

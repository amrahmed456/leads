<?php

namespace App\Sources;

use App\Discovery\Data\DiscoveredBusiness;
use App\Models\SourcePlace;

/**
 * Turns a locally stored Overture / AllThePlaces place into the provider-
 * independent shape every other part of the pipeline understands.
 */
final class SourcePlaceMapper
{
    public function toDiscovered(SourcePlace $place): DiscoveredBusiness
    {
        return new DiscoveredBusiness(
            provider: $place->source,
            providerId: $place->source_record_id,
            name: $place->name,
            alternateName: $place->extra['alternate_name'] ?? null,
            category: $place->mapped_category,
            type: $place->category,
            countryCode: $place->country_code,
            address: $place->address,
            latitude: $place->latitude,
            longitude: $place->longitude,
            phone: $place->phone,
            website: $place->website,
            email: $place->email,
            openingHours: $place->extra['opening_hours'] ?? null,
            socialLinks: $place->socials ?? [],
            sourceUrl: $place->source_url,
            raw: array_filter([
                'id' => $place->source_record_id,
                'category' => $place->category,
                'taxonomy' => $place->taxonomy,
                'confidence' => $place->confidence,
                'brand' => $place->brand_name,
                'brand_wikidata' => $place->brand_wikidata,
                'operator' => $place->operator,
                'branch' => $place->branch,
                'spider' => $place->extra['spider'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            brand: $place->brand_name,
            brandWikidata: $place->brand_wikidata,
            operator: $place->operator,
            confidence: $place->confidence,
            operatingStatus: $place->operating_status,
            provenance: $place->provenance ?? [],
        );
    }
}

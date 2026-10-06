<?php

namespace App\Identity;

use App\Enums\EvidenceFact;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\SourcePlace;

/**
 * Counts how many distinct locations share an identity marker (brand,
 * own website domain, phone, social account or name), and in how many
 * cities. Repetition across locations is chain evidence; repetition of a
 * name alone is only a weak hint.
 */
final class BranchCounter
{
    /**
     * @return array{locations: int, cities: int}
     */
    public function forBrand(Brand $brand): array
    {
        $known = Business::query()->where('brand_id', $brand->getKey());
        $places = SourcePlace::query()->where(fn ($query) => $brand->wikidata_id !== null
            ? $query->where('brand_wikidata', $brand->wikidata_id)
            : $query->where('brand_name', $brand->name));

        return [
            'locations' => max($brand->location_count ?? 0, (clone $known)->count(), (clone $places)->count()),
            'cities' => max(
                (clone $known)->whereNotNull('city')->distinct()->count('city'),
                (clone $places)->whereNotNull('city')->distinct()->count('city'),
            ),
        ];
    }

    /**
     * Businesses sharing an evidence value (website domain, phone, social URL).
     *
     * @return array{locations: int, cities: int}
     */
    public function sharing(EvidenceFact $fact, string $normalizedValue): array
    {
        $businessIds = BusinessEvidence::query()
            ->where('fact_type', $fact)
            ->where('value_normalized', $normalizedValue)
            ->distinct()
            ->limit(500)
            ->pluck('business_id');

        return [
            'locations' => $businessIds->count(),
            'cities' => Business::query()->whereKey($businessIds)->whereNotNull('city')->distinct()->count('city'),
        ];
    }

    /**
     * Businesses with exactly the same normalized name (a weak hint only).
     *
     * @return array{locations: int, cities: int}
     */
    public function sameName(Business $business): array
    {
        $query = Business::query()->where('name_normalized', $business->name_normalized);

        return [
            'locations' => (clone $query)->count(),
            'cities' => (clone $query)->whereNotNull('city')->distinct()->count('city'),
        ];
    }
}

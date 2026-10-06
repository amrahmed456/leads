<?php

namespace App\Sources\AllThePlaces;

use App\Discovery\Providers\OpenStreetMap\OsmTagMap;
use App\Models\AtpSpider;
use App\Support\Normalization\Text;

/**
 * Converts a feature of an AllThePlaces spider (OSM-style tags taken from
 * a brand's official store locator) into a source_places row.
 */
final class AtpFeatureMapper
{
    public function __construct(private readonly OsmTagMap $tags) {}

    /**
     * @param  array<mixed>  $feature
     * @return array<string, mixed>|null Null when the feature has no usable location
     */
    public function map(array $feature, AtpSpider $spider): ?array
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if (($feature['geometry']['type'] ?? null) !== 'Point' || ! is_array($coordinates)
            || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        [$longitude, $latitude] = [(float) $coordinates[0], (float) $coordinates[1]];

        if (abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
            return null;
        }

        $tags = array_filter(array_map(
            fn (mixed $value): ?string => is_scalar($value) ? trim((string) $value) : null,
            $properties,
        ), fn (?string $value): bool => $value !== null && $value !== '');

        $brand = Text::clean($tags['brand'] ?? $spider->brand_name, 150);
        $branch = Text::clean($tags['branch'] ?? null, 150);
        $name = Text::clean($tags['name'] ?? (($brand ?? $spider->brand_name).($branch !== null ? " {$branch}" : '')), 255);

        // Some store locators name a branch only by its location ("Riyadh, Faisaliah Mall").
        if ($name !== null && $brand !== null && ! str_contains(Text::matchKey($name), Text::matchKey($brand))) {
            $branch ??= $name;
            $name = Text::clean("{$brand} – {$name}", 255);
        }
        $ref = $tags['ref'] ?? (is_scalar($feature['id'] ?? null) ? (string) $feature['id'] : null) ?? sprintf('%.6F,%.6F', $latitude, $longitude);
        $primaryTag = $this->tags->primaryTag($tags);

        return [
            'source' => 'alltheplaces',
            'source_record_id' => mb_substr($spider->spider.'/'.$ref, 0, 191),
            'name' => $name ?? $spider->brand_name,
            'brand_name' => $brand,
            'brand_wikidata' => $this->wikidata($tags['brand:wikidata'] ?? $spider->brand_wikidata),
            'operator' => Text::clean($tags['operator'] ?? null, 150),
            'branch' => $branch,
            'category' => $primaryTag === null ? null : $primaryTag[1],
            'mapped_category' => $this->tags->categoryFor($tags)?->value,
            'confidence' => null,
            'operating_status' => null,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'country_code' => isset($tags['addr:country']) && preg_match('/^[A-Za-z]{2}$/', $tags['addr:country']) ? strtoupper($tags['addr:country']) : null,
            'city' => Text::clean($tags['addr:city'] ?? null, 120),
            'address' => Text::clean($tags['addr:full'] ?? trim(($tags['addr:street_address'] ?? '').' '.($tags['addr:city'] ?? '')), 500),
            'phone' => Text::clean($tags['phone'] ?? null, 32),
            'website' => Text::clean($tags['website'] ?? null, 500),
            'email' => Text::clean($tags['email'] ?? null, 255),
            'socials' => array_filter([
                'facebook' => $tags['contact:facebook'] ?? null,
                'instagram' => $tags['contact:instagram'] ?? null,
                'x' => $tags['contact:twitter'] ?? null,
            ]) ?: null,
            'provenance' => [['dataset' => 'AllThePlaces', 'record_id' => $ref, 'spider' => $spider->spider]],
            'source_url' => isset($tags['@source_uri']) && str_starts_with($tags['@source_uri'], 'http') ? mb_substr($tags['@source_uri'], 0, 500) : null,
            'extra' => array_filter([
                'spider' => $spider->spider,
                'opening_hours' => $tags['opening_hours'] ?? null,
                'nsi_id' => $tags['nsi_id'] ?? null,
            ]) ?: null,
        ];
    }

    private function wikidata(?string $value): ?string
    {
        return $value !== null && preg_match('/^Q\d{1,12}$/', $value) ? $value : null;
    }
}

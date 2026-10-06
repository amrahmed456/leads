<?php

namespace App\Sources\Overture;

use App\Support\Normalization\Text;

/**
 * Converts one Overture Places record into a source_places row.
 *
 * Accepts the shapes Overture extracts come in: GeoJSON features (from the
 * overturemaps CLI or DuckDB), flat NDJSON rows, and CSV rows whose nested
 * fields are JSON-encoded or flattened ("names.primary"). Overture's
 * confidence is kept as an existence signal only: how likely the place is
 * real and current, never how likely it is to buy anything.
 */
final class OvertureRecordMapper
{
    private const JSON_FIELDS = ['names', 'categories', 'taxonomy', 'brand', 'addresses', 'sources', 'websites', 'socials', 'emails', 'phones', 'bbox', 'geometry'];

    public function __construct(private readonly OvertureCategoryMap $categories) {}

    /**
     * @param  array<mixed>  $record
     * @return array<string, mixed>|null Null when the record is malformed (no ID, name or location)
     */
    public function map(array $record): ?array
    {
        $props = isset($record['properties']) && is_array($record['properties']) ? $record['properties'] : $record;

        foreach (self::JSON_FIELDS as $field) {
            if (isset($props[$field]) && is_string($props[$field]) && preg_match('/^\s*[\[{]/', $props[$field])) {
                $props[$field] = json_decode($props[$field], true);
            }
        }

        $id = Text::clean($this->string($props['id'] ?? $record['id'] ?? null), 191);
        $name = Text::clean($this->string($props['names']['primary'] ?? $props['names.primary'] ?? $props['name'] ?? null), 255);
        $coordinates = $this->coordinates($record, $props);

        if ($id === null || $name === null || $coordinates === null) {
            return null;
        }

        $categoryChain = $this->categoryChain($props);
        $address = is_array($props['addresses'][0] ?? null) ? $props['addresses'][0] : [];
        $confidence = is_numeric($props['confidence'] ?? null) ? round(max(0.0, min(1.0, (float) $props['confidence'])), 3) : null;

        return [
            'source' => 'overture',
            'source_record_id' => $id,
            'name' => $name,
            'brand_name' => Text::clean($this->string($props['brand']['names']['primary'] ?? $props['brand.names.primary'] ?? null), 150),
            'brand_wikidata' => $this->wikidata($props['brand']['wikidata'] ?? $props['brand.wikidata'] ?? null),
            'category' => $categoryChain[0] ?? null,
            'mapped_category' => $this->categories->categoryFor($categoryChain)?->value,
            'taxonomy' => $categoryChain === [] ? null : ['chain' => array_slice($categoryChain, 0, 8)],
            'confidence' => $confidence,
            'operating_status' => Text::clean($this->string($props['operating_status'] ?? null), 30),
            'latitude' => round($coordinates[0], 7),
            'longitude' => round($coordinates[1], 7),
            'country_code' => $this->countryCode($address['country'] ?? $props['addresses.country'] ?? null),
            'city' => Text::clean($this->string($address['locality'] ?? $props['addresses.locality'] ?? null), 120),
            'address' => Text::clean($this->string($address['freeform'] ?? $props['addresses.freeform'] ?? null), 500),
            'phone' => Text::clean($this->first($props['phones'] ?? null), 32),
            'website' => Text::clean($this->first($props['websites'] ?? null), 500),
            'email' => Text::clean($this->first($props['emails'] ?? null), 255),
            'socials' => $this->socials($props['socials'] ?? null),
            'provenance' => $this->provenance($props['sources'] ?? null),
            'source_url' => null,
            'extra' => array_filter([
                'alternate_name' => $this->alternateName($props['names'] ?? null, $name),
                'release' => $this->string($props['version'] ?? null),
            ]) ?: null,
        ];
    }

    /**
     * @param  array<mixed>  $record
     * @param  array<mixed>  $props
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private function coordinates(array $record, array $props): ?array
    {
        $geometry = $record['geometry'] ?? $props['geometry'] ?? null;

        if (is_array($geometry) && ($geometry['type'] ?? null) === 'Point' && is_array($geometry['coordinates'] ?? null)) {
            [$longitude, $latitude] = array_values($geometry['coordinates']) + [null, null];
        } elseif (is_string($geometry) && preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $geometry, $matches)) {
            [$longitude, $latitude] = [$matches[1], $matches[2]];
        } elseif (is_array($props['bbox'] ?? null) && isset($props['bbox']['xmin'], $props['bbox']['ymin'], $props['bbox']['xmax'], $props['bbox']['ymax'])) {
            $longitude = ((float) $props['bbox']['xmin'] + (float) $props['bbox']['xmax']) / 2;
            $latitude = ((float) $props['bbox']['ymin'] + (float) $props['bbox']['ymax']) / 2;
        } else {
            $latitude = $props['latitude'] ?? $props['lat'] ?? null;
            $longitude = $props['longitude'] ?? $props['lon'] ?? $props['lng'] ?? null;
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        // (0, 0) is a placeholder, not a place.
        if (abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Categories from most to least specific, across Overture schema versions.
     *
     * @param  array<mixed>  $props
     * @return list<string>
     */
    private function categoryChain(array $props): array
    {
        $chain = [
            $props['taxonomy']['primary'] ?? null,
            $props['categories']['primary'] ?? $props['categories.primary'] ?? $props['category'] ?? null,
            $props['basic_category'] ?? null,
        ];

        if (is_array($props['taxonomy']['hierarchy'] ?? null)) {
            array_push($chain, ...array_reverse($props['taxonomy']['hierarchy']));
        }

        if (is_array($props['categories']['alternate'] ?? null)) {
            array_push($chain, ...$props['categories']['alternate']);
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $category): ?string => is_string($category) && trim($category) !== '' ? strtolower(trim($category)) : null,
            $chain,
        ))));
    }

    private function first(mixed $values): ?string
    {
        if (is_string($values)) {
            return trim(explode(';', $values)[0]) ?: null;
        }

        return is_array($values) ? $this->string(array_values($values)[0] ?? null) : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function socials(mixed $values): ?array
    {
        $links = [];

        foreach (is_array($values) ? $values : (is_string($values) ? explode(';', $values) : []) as $url) {
            $url = $this->string($url);

            if ($url !== null && preg_match('#(facebook|instagram|twitter|x|tiktok|snapchat|linkedin|youtube)\.com#i', $url, $matches)) {
                $network = strtolower($matches[1]) === 'twitter' ? 'x' : strtolower($matches[1]);
                $links[$network] ??= $url;
            }
        }

        return $links === [] ? null : $links;
    }

    /**
     * @return list<array{dataset: string, record_id: ?string, property: ?string, update_time: ?string, confidence: ?float}>|null
     */
    private function provenance(mixed $sources): ?array
    {
        if (! is_array($sources)) {
            return null;
        }

        $provenance = [];

        foreach (array_slice($sources, 0, 10) as $source) {
            if (is_array($source) && is_string($source['dataset'] ?? null)) {
                $provenance[] = [
                    'dataset' => $source['dataset'],
                    'record_id' => $this->string($source['record_id'] ?? null),
                    'property' => $this->string($source['property'] ?? null),
                    'update_time' => $this->string($source['update_time'] ?? null),
                    'confidence' => is_numeric($source['confidence'] ?? null) ? (float) $source['confidence'] : null,
                ];
            }
        }

        return $provenance === [] ? null : $provenance;
    }

    private function alternateName(mixed $names, string $primary): ?string
    {
        $common = is_array($names) ? ($names['common'] ?? null) : null;

        if (! is_array($common)) {
            return null;
        }

        // Older releases: {"ar": "..."}; newer: [{"language": "ar", "value": "..."}].
        foreach ($common as $language => $value) {
            $text = is_array($value) ? $this->string($value['value'] ?? null) : $this->string($value);
            $code = is_array($value) ? (string) ($value['language'] ?? '') : (string) $language;

            if ($text !== null && in_array($code, ['ar', 'en'], true) && Text::matchKey($text) !== Text::matchKey($primary)) {
                return Text::clean($text, 255);
            }
        }

        return null;
    }

    private function wikidata(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $this->string($value)));

        return preg_match('/^Q\d{1,12}$/', $value) ? $value : null;
    }

    private function countryCode(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $this->string($value)));

        return preg_match('/^[A-Z]{2}$/', $value) ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}

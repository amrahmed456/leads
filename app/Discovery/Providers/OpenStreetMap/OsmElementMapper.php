<?php

namespace App\Discovery\Providers\OpenStreetMap;

use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\Data\DiscoveryQuery;
use App\Support\Normalization\Text;

/**
 * Converts Overpass API elements into DiscoveredBusiness objects.
 */
final class OsmElementMapper
{
    private const ELEMENT_TYPES = ['node', 'way', 'relation'];

    private const NAME_TAGS = ['name', 'name:en', 'name:ar', 'brand', 'brand:en', 'brand:ar'];

    private const ALTERNATE_NAME_TAGS = ['name:en', 'name:ar', 'int_name', 'brand:en', 'brand:ar'];

    private const PHONE_TAGS = ['phone', 'contact:phone', 'mobile', 'contact:mobile'];

    private const WEBSITE_TAGS = ['website', 'contact:website', 'url'];

    private const EMAIL_TAGS = ['email', 'contact:email'];

    /** @var array<string, list<string>> network => tags */
    private const SOCIAL_TAGS = [
        'facebook' => ['contact:facebook', 'facebook'],
        'instagram' => ['contact:instagram', 'instagram'],
        'x' => ['contact:twitter', 'twitter', 'contact:x'],
        'tiktok' => ['contact:tiktok', 'tiktok'],
        'snapchat' => ['contact:snapchat', 'snapchat'],
        'linkedin' => ['contact:linkedin', 'linkedin'],
        'youtube' => ['contact:youtube', 'youtube'],
        'whatsapp' => ['contact:whatsapp', 'whatsapp'],
        'telegram' => ['contact:telegram', 'telegram'],
    ];

    public function __construct(private readonly OsmTagMap $tagMap) {}

    /**
     * Returns null when the element is malformed, has no name, or is not a business.
     *
     * @param  array<mixed>  $element
     */
    public function map(array $element, DiscoveryQuery $query): ?DiscoveredBusiness
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        if (! in_array($type, self::ELEMENT_TYPES, true) || ! $this->isPositiveInteger($id)) {
            return null;
        }

        $tags = $this->stringTags($element['tags'] ?? null);
        $coordinates = $this->coordinates($element, $type);
        $name = $this->first($tags, self::NAME_TAGS);

        if ($tags === [] || $coordinates === null || $name === null) {
            return null;
        }

        $category = $this->tagMap->categoryFor($tags);
        $primaryTag = $this->tagMap->primaryTag($tags);

        // Keyword matches can include public facilities (parking, mosques, parks...).
        if ($category === null && ($primaryTag === null || $this->tagMap->isNonBusiness(...$primaryTag))) {
            return null;
        }

        $id = (int) $id;

        return new DiscoveredBusiness(
            provider: OpenStreetMapProvider::KEY,
            providerId: "{$type}/{$id}",
            name: $name,
            alternateName: $this->alternateName($tags, $name),
            category: $category,
            type: $primaryTag === null ? null : ($primaryTag[1] === 'yes' ? $primaryTag[0] : $primaryTag[1]),
            countryCode: $this->countryCode($tags) ?? $query->country->value,
            address: $this->address($tags),
            latitude: $coordinates[0],
            longitude: $coordinates[1],
            // Several phone tags are joined; the normalizer keeps the first valid number.
            phone: $this->all($tags, self::PHONE_TAGS),
            website: $this->first($tags, self::WEBSITE_TAGS),
            email: $this->first($tags, self::EMAIL_TAGS),
            // OpenStreetMap has no ratings or reviews.
            rating: null,
            reviewsCount: null,
            openingHours: $tags['opening_hours'] ?? null,
            socialLinks: $this->socialLinks($tags),
            sourceUrl: "https://www.openstreetmap.org/{$type}/{$id}",
            // OSM data is ODbL-licensed and may be stored with attribution.
            raw: ['type' => $type, 'id' => $id, 'tags' => $tags],
            brand: $tags['brand'] ?? null,
            brandWikidata: $tags['brand:wikidata'] ?? null,
            operator: $tags['operator'] ?? null,
            operatingStatus: $this->operatingStatus($tags),
        );
    }

    /**
     * @return array<string, string>
     */
    private function stringTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];

        foreach ($tags as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $clean[$key] = $value;
                }
            }
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $element
     * @return array{0: float, 1: float}|null
     */
    private function coordinates(array $element, string $type): ?array
    {
        $source = $type === 'node' ? $element : ($element['center'] ?? null);

        if (! is_array($source) || ! is_numeric($source['lat'] ?? null) || ! is_numeric($source['lon'] ?? null)) {
            return null;
        }

        $latitude = (float) $source['lat'];
        $longitude = (float) $source['lon'];

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * @param  array<string, string>  $tags
     * @param  list<string>  $keys
     */
    private function first(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($tags[$key])) {
                return $tags[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $tags
     * @param  list<string>  $keys
     */
    private function all(array $tags, array $keys): ?string
    {
        $values = array_values(array_unique(array_filter(array_map(fn (string $key) => $tags[$key] ?? null, $keys))));

        return $values === [] ? null : implode(';', $values);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function alternateName(array $tags, string $name): ?string
    {
        $nameKey = Text::matchKey($name);

        foreach ([...self::ALTERNATE_NAME_TAGS, 'name'] as $key) {
            if (isset($tags[$key]) && Text::matchKey($tags[$key]) !== $nameKey) {
                return $tags[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function countryCode(array $tags): ?string
    {
        $country = $tags['addr:country'] ?? '';

        return preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null;
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function address(array $tags): ?string
    {
        if (isset($tags['addr:full'])) {
            return $tags['addr:full'];
        }

        $street = trim(($tags['addr:housenumber'] ?? '').' '.($tags['addr:street'] ?? ''));

        $parts = array_filter([
            $street,
            $this->first($tags, ['addr:district', 'addr:suburb', 'addr:neighbourhood', 'addr:quarter']),
            $tags['addr:city'] ?? null,
            $tags['addr:postcode'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private function socialLinks(array $tags): array
    {
        $links = [];

        foreach (self::SOCIAL_TAGS as $network => $keys) {
            $value = $this->first($tags, $keys);

            if ($value !== null) {
                $links[$network] = $value;
            }
        }

        return $links;
    }

    /**
     * Lifecycle prefixes ("disused:shop", "was:amenity") mean the business has closed.
     *
     * @param  array<string, string>  $tags
     */
    private function operatingStatus(array $tags): ?string
    {
        foreach (array_keys($tags) as $key) {
            if (preg_match('/^(?:disused|abandoned|was|demolished|razed):(?:amenity|shop|office|tourism|leisure|healthcare|craft)$/', $key)) {
                return 'permanently_closed';
            }
        }

        return null;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }
}

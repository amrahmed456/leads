<?php

namespace App\Auditing;

use App\Support\Normalization\Text;

/**
 * Extracts the organizations and local businesses a homepage describes
 * about itself in Schema.org markup (JSON-LD, microdata, RDFa), in one
 * normalized shape: name, legal name, contact details, address, location,
 * official profiles (sameAs), brand, parentOrganization and branchOf.
 *
 * This is first-party evidence: the business stating who it is.
 */
final class StructuredDataExtractor
{
    private const MAX_ENTITIES = 10;

    /** Types that describe content, not the organization behind the site. */
    private const IGNORED_TYPES = [
        'website', 'webpage', 'webpageelement', 'sitenavigationelement', 'breadcrumblist', 'listitem', 'itemlist',
        'imageobject', 'videoobject', 'product', 'offer', 'aggregateoffer', 'review', 'aggregaterating', 'rating',
        'article', 'blogposting', 'newsarticle', 'faqpage', 'question', 'answer', 'searchaction', 'readaction',
        'person', 'event', 'menu', 'menusection', 'menuitem', 'howto', 'recipe', 'collectionpage', 'contactpoint',
        'postaladdress', 'geocoordinates', 'openinghoursspecification', 'place',
    ];

    /**
     * @return array{entities: list<array<string, mixed>>}
     */
    public function extract(HtmlDocument $document): array
    {
        $entities = [];

        foreach ($document->jsonLd() as $block) {
            foreach ($this->flatten($block) as $node) {
                $entities[] = $this->normalize($node, 'json-ld');
            }
        }

        foreach ($document->microdataAndRdfa() as $item) {
            $entities[] = $this->normalize($item, 'microdata');
        }

        $entities = array_values(array_filter($entities, fn (?array $entity): bool => $entity !== null));

        return ['entities' => array_slice($entities, 0, self::MAX_ENTITIES)];
    }

    /**
     * Every typed object of a JSON-LD block that may describe an organization.
     *
     * @param  array<mixed>  $data
     * @return list<array<mixed>>
     */
    private function flatten(array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        if (array_is_list($data)) {
            return array_merge(...array_map(fn (mixed $item): array => is_array($item) ? $this->flatten($item, $depth + 1) : [], $data) ?: [[]]);
        }

        if (is_array($data['@graph'] ?? null)) {
            return $this->flatten($data['@graph'], $depth + 1);
        }

        return isset($data['@type']) ? [$data] : [];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null
     */
    private function normalize(array $node, string $format): ?array
    {
        $types = array_values(array_filter(array_map(
            fn (mixed $type): ?string => is_string($type) ? (string) preg_replace('#^(?:https?://schema\.org/|schema:)#i', '', $type) : null,
            (array) ($node['@type'] ?? []),
        )));

        if ($types === [] || array_diff(array_map('strtolower', $types), self::IGNORED_TYPES) === []) {
            return null;
        }

        $name = $this->text($node['name'] ?? null);

        if ($name === null && $this->text($node['legalName'] ?? null) === null) {
            return null;
        }

        return array_filter([
            'types' => $types,
            'format' => $format,
            'name' => $name,
            'legal_name' => $this->text($node['legalName'] ?? null),
            'url' => $this->text($node['url'] ?? null),
            'telephone' => $this->text($node['telephone'] ?? ($node['contactPoint']['telephone'] ?? null)),
            'email' => $this->text($node['email'] ?? ($node['contactPoint']['email'] ?? null)),
            'address' => $this->address($node['address'] ?? null),
            'latitude' => is_numeric($node['geo']['latitude'] ?? null) ? (float) $node['geo']['latitude'] : null,
            'longitude' => is_numeric($node['geo']['longitude'] ?? null) ? (float) $node['geo']['longitude'] : null,
            'opening_hours' => $this->text(is_array($node['openingHours'] ?? null) ? implode('; ', array_filter($node['openingHours'], 'is_string')) : ($node['openingHours'] ?? null)),
            'same_as' => array_values(array_filter(array_map(fn (mixed $url): ?string => $this->text($url), array_slice((array) ($node['sameAs'] ?? []), 0, 15)))),
            'brand' => $this->organizationName($node['brand'] ?? null),
            'parent_organization' => $this->organizationName($node['parentOrganization'] ?? null),
            'branch_of' => $this->organizationName($node['branchOf'] ?? null),
            'has_menu' => isset($node['hasMenu']) || isset($node['menu']) ? true : null,
            'accepts_reservations' => $this->boolean($node['acceptsReservations'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function organizationName(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = array_is_list($value) ? ($value[0] ?? null) : $value;

            return is_array($value) ? $this->text($value['name'] ?? $value['legalName'] ?? null) : $this->text($value);
        }

        return $this->text($value);
    }

    private function address(mixed $address): ?string
    {
        if (is_array($address)) {
            $address = array_is_list($address) ? ($address[0] ?? null) : $address;
        }

        if (! is_array($address)) {
            return $this->text($address);
        }

        return $this->text(implode(', ', array_filter(array_map(
            fn (string $key): ?string => is_string($address[$key] ?? null) ? $address[$key] : null,
            ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'],
        ))));
    }

    private function boolean(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) && in_array(strtolower($value), ['true', 'yes', 'https://schema.org/true'], true) => true,
            is_string($value) && in_array(strtolower($value), ['false', 'no', 'https://schema.org/false'], true) => false,
            default => null,
        };
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? Text::clean((string) $value, 500) : null;
    }
}

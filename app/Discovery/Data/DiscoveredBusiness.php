<?php

namespace App\Discovery\Data;

use App\Enums\BusinessCategory;

/**
 * A business as reported by a discovery provider, in a provider-independent
 * shape. Providers fill it with the values they extracted; BusinessNormalizer
 * then cleans and validates those values before anything is stored.
 */
final readonly class DiscoveredBusiness
{
    /**
     * @param  array<string, string>  $socialLinks  network => URL or handle
     * @param  array<string, mixed>|null  $raw  Provider data worth keeping, if its licence allows it
     * @param  string|null  $brand  Brand the location belongs to (never replaces the name)
     * @param  string|null  $brandWikidata  Wikidata ID of the brand, e.g. Q524757
     * @param  string|null  $operator  Company operating the location, when stated
     * @param  float|null  $confidence  The source's own 0-1 confidence that the place exists (not lead quality)
     * @param  string|null  $operatingStatus  open, temporarily_closed, permanently_closed
     * @param  array<string, string>  $identifiers  Strong identifiers in other sources: source key => record ID
     *                                              (e.g. Geoapify results carry their OpenStreetMap ID)
     * @param  list<array<string, mixed>>  $provenance  Where the record's data originally came from
     * @param  string|null  $independenceGroup  Overrides the provider's group when provenance shows another origin
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public string $name,
        public ?string $alternateName = null,
        public ?BusinessCategory $category = null,
        public ?string $type = null,
        public ?string $countryCode = null,
        public ?string $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $email = null,
        public ?float $rating = null,
        public ?int $reviewsCount = null,
        public ?string $openingHours = null,
        public array $socialLinks = [],
        public ?string $sourceUrl = null,
        public ?array $raw = null,
        public ?string $brand = null,
        public ?string $brandWikidata = null,
        public ?string $operator = null,
        public ?float $confidence = null,
        public ?string $operatingStatus = null,
        public array $identifiers = [],
        public array $provenance = [],
        public ?string $independenceGroup = null,
    ) {}

    /**
     * Return a copy with the given properties replaced.
     */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}

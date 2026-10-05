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
    ) {}

    /**
     * Return a copy with the given properties replaced.
     */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}

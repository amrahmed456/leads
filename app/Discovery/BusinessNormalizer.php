<?php

namespace App\Discovery;

use App\Discovery\Data\DiscoveredBusiness;
use App\Enums\Country;
use App\Support\Normalization\EmailNormalizer;
use App\Support\Normalization\PhoneNormalizer;
use App\Support\Normalization\SocialLinkNormalizer;
use App\Support\Normalization\Text;
use App\Support\Normalization\UrlNormalizer;

/**
 * Cleans and validates provider records before they reach the database.
 *
 * Applied to every provider's output, so data quality rules live in one
 * place regardless of where the data came from.
 */
final class BusinessNormalizer
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly UrlNormalizer $urls,
        private readonly EmailNormalizer $emails,
        private readonly SocialLinkNormalizer $socialLinks,
    ) {}

    /**
     * Returns null when the record lacks the minimum needed to store it.
     */
    public function normalize(DiscoveredBusiness $business): ?DiscoveredBusiness
    {
        $provider = Text::clean($business->provider, 50);
        $providerId = Text::clean($business->providerId, 191);
        $name = Text::clean($business->name, 255);

        if ($provider === null || $providerId === null || $name === null || Text::matchKey($name) === '') {
            return null;
        }

        $countryCode = preg_match('/^[A-Za-z]{2}$/', trim((string) $business->countryCode))
            ? strtoupper(trim((string) $business->countryCode))
            : null;
        $country = Country::tryFrom((string) $countryCode);

        [$latitude, $longitude] = $this->coordinates($business->latitude, $business->longitude);

        $alternateName = Text::clean($business->alternateName, 255);
        if ($alternateName !== null && Text::matchKey($alternateName) === Text::matchKey($name)) {
            $alternateName = null;
        }

        // A social profile listed as the "website" is not a website. Keeping the two
        // apart matters: a business with only an Instagram page has no website.
        $socialLinks = $business->socialLinks;
        $website = $this->urls->normalize($business->website);
        $websiteNetwork = $website === null ? null : $this->socialLinks->networkFor($website);

        if ($websiteNetwork !== null) {
            $socialLinks[$websiteNetwork] ??= $website;
            $website = null;
        }

        return new DiscoveredBusiness(
            provider: $provider,
            providerId: $providerId,
            name: $name,
            alternateName: $alternateName,
            category: $business->category,
            type: Text::clean($business->type, 100),
            countryCode: $countryCode,
            address: Text::clean($business->address, 500),
            latitude: $latitude,
            longitude: $longitude,
            phone: $this->phones->normalize($business->phone, $country),
            website: $website,
            email: $this->emails->normalize($business->email),
            rating: $this->rating($business->rating),
            reviewsCount: $business->reviewsCount !== null && $business->reviewsCount >= 0 ? $business->reviewsCount : null,
            openingHours: Text::clean($business->openingHours, 255),
            socialLinks: $this->socialLinks->normalize($socialLinks, $country),
            sourceUrl: $this->urls->normalize($business->sourceUrl),
            raw: $business->raw,
            brand: Text::clean($business->brand, 150),
            brandWikidata: $this->wikidataId($business->brandWikidata),
            operator: Text::clean($business->operator, 150),
            confidence: $business->confidence !== null && is_finite($business->confidence)
                ? round(max(0.0, min(1.0, $business->confidence)), 3)
                : null,
            operatingStatus: $this->operatingStatus($business->operatingStatus),
            identifiers: array_filter(
                array_map(fn (mixed $id): ?string => is_scalar($id) ? Text::clean((string) $id, 191) : null, $business->identifiers),
            ),
            provenance: $business->provenance,
            independenceGroup: Text::clean($business->independenceGroup, 50),
        );
    }

    private function wikidataId(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^Q[1-9]\d{0,12}$/', $value) ? $value : null;
    }

    private function operatingStatus(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['open', 'temporarily_closed', 'permanently_closed', 'closed'], true)
            ? ($value === 'closed' ? 'permanently_closed' : $value)
            : null;
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function coordinates(?float $latitude, ?float $longitude): array
    {
        if ($latitude === null || $longitude === null
            || ! is_finite($latitude) || ! is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            return [null, null];
        }

        return [round($latitude, 7), round($longitude, 7)];
    }

    private function rating(?float $rating): ?float
    {
        return $rating !== null && is_finite($rating) && $rating >= 0 && $rating <= 5 ? round($rating, 2) : null;
    }
}

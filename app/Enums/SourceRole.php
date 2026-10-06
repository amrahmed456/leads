<?php

namespace App\Enums;

/**
 * What a data source is used for. A source may have several roles.
 */
enum SourceRole: string
{
    case Discovery = 'discovery';
    case Verification = 'verification';
    case Enrichment = 'enrichment';
    case BrandIntelligence = 'brand_intelligence';
    case GeographicContext = 'geographic_context';
    case AddressContext = 'address_context';

    public function label(): string
    {
        return match ($this) {
            self::Discovery => 'Discovery',
            self::Verification => 'Verification',
            self::Enrichment => 'Enrichment',
            self::BrandIntelligence => 'Brand intelligence',
            self::GeographicContext => 'Geographic context',
            self::AddressContext => 'Address context',
        };
    }

    /**
     * Whether evidence from this role can confirm that a business exists.
     * Context sources (cities, addresses) and brand registries cannot.
     */
    public function confirmsExistence(): bool
    {
        return $this === self::Discovery || $this === self::Verification;
    }
}

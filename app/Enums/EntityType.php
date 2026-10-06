<?php

namespace App\Enums;

/**
 * What kind of organization a business location belongs to.
 */
enum EntityType: string
{
    case Independent = 'independent';
    case LocalBusiness = 'local_business';
    case LocalMultiBranch = 'local_multi_branch';
    case Franchise = 'franchise';
    case RegionalChain = 'regional_chain';
    case NationalChain = 'national_chain';
    case InternationalChain = 'international_chain';
    case Enterprise = 'enterprise';
    case Corporate = 'corporate';
    case Government = 'government';
    case Nonprofit = 'nonprofit';
    case Aggregator = 'aggregator';
    case Directory = 'directory';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Independent => 'Independent',
            self::LocalBusiness => 'Local business',
            self::LocalMultiBranch => 'Local multi-branch',
            self::Franchise => 'Franchise',
            self::RegionalChain => 'Regional chain',
            self::NationalChain => 'National chain',
            self::InternationalChain => 'International chain',
            self::Enterprise => 'Enterprise',
            self::Corporate => 'Corporate',
            self::Government => 'Government',
            self::Nonprofit => 'Non-profit',
            self::Aggregator => 'Aggregator',
            self::Directory => 'Directory',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Small or local businesses that make their own decisions.
     */
    public function isIndependentKind(): bool
    {
        return in_array($this, [self::Independent, self::LocalBusiness, self::LocalMultiBranch], true);
    }

    /**
     * Branches of a brand, or parts of an organization with central decision making.
     */
    public function isChainKind(): bool
    {
        return in_array($this, [
            self::Franchise, self::RegionalChain, self::NationalChain, self::InternationalChain,
            self::Enterprise, self::Corporate,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function chainKinds(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isChainKind()));
    }

    /**
     * @return list<self>
     */
    public static function independentKinds(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isIndependentKind()));
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}

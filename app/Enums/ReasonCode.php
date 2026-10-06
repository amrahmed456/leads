<?php

namespace App\Enums;

/**
 * Machine-readable reasons behind entity classification and lead
 * eligibility. Every stored reason keeps the code and a human-readable,
 * case-specific explanation.
 */
enum ReasonCode: string
{
    // Chain / organization evidence.
    case KnownBrand = 'KNOWN_BRAND';
    case PossibleBrandMatch = 'POSSIBLE_BRAND_MATCH';
    case InternationalChain = 'INTERNATIONAL_CHAIN';
    case NationalChain = 'NATIONAL_CHAIN';
    case RegionalChain = 'REGIONAL_CHAIN';
    case Franchise = 'FRANCHISE';
    case Enterprise = 'ENTERPRISE';
    case Corporate = 'CORPORATE';
    case Government = 'GOVERNMENT';
    case Nonprofit = 'NONPROFIT';
    case Directory = 'DIRECTORY';
    case Aggregator = 'AGGREGATOR';
    case MultipleBranches = 'MULTIPLE_BRANCHES';
    case CorporateDomain = 'CORPORATE_DOMAIN';
    case RepeatedDomain = 'REPEATED_DOMAIN';
    case SharedPhone = 'SHARED_PHONE';
    case SharedSocialAccount = 'SHARED_SOCIAL_ACCOUNT';
    case ParentOrganization = 'PARENT_ORGANIZATION';
    case BranchOf = 'BRANCH_OF';
    case FirstPartyChainSource = 'FIRST_PARTY_CHAIN_SOURCE';
    case CorporatePages = 'CORPORATE_PAGES';

    // Independent-business evidence.
    case IndependentBusiness = 'INDEPENDENT_BUSINESS';
    case SingleLocation = 'SINGLE_LOCATION';
    case OwnWebsite = 'OWN_WEBSITE';

    // Data quality.
    case LowDataConfidence = 'LOW_DATA_CONFIDENCE';
    case ConflictingIdentity = 'CONFLICTING_IDENTITY';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case PermanentlyClosed = 'PERMANENTLY_CLOSED';
    case UnknownEntity = 'UNKNOWN_ENTITY';

    // Lead qualification.
    case StrongDigitalPresence = 'STRONG_DIGITAL_PRESENCE';
    case LowBusinessFit = 'LOW_BUSINESS_FIT';
    case LowOpportunity = 'LOW_OPPORTUNITY';
    case NoContactRoute = 'NO_CONTACT_ROUTE';
    case NoSellableOpportunity = 'NO_SELLABLE_OPPORTUNITY';
    case MeetsHotCriteria = 'MEETS_HOT_CRITERIA';
    case MeetsWarmCriteria = 'MEETS_WARM_CRITERIA';

    public function label(): string
    {
        return match ($this) {
            self::KnownBrand => 'Recognized brand',
            self::PossibleBrandMatch => 'Name resembles a known brand',
            self::InternationalChain => 'International chain',
            self::NationalChain => 'National chain',
            self::RegionalChain => 'Regional chain',
            self::Franchise => 'Franchise',
            self::Enterprise => 'Large enterprise',
            self::Corporate => 'Part of a corporate group',
            self::Government => 'Government entity',
            self::Nonprofit => 'Non-profit organization',
            self::Directory => 'Directory / listing',
            self::Aggregator => 'Marketplace / aggregator',
            self::MultipleBranches => 'Multiple branches',
            self::CorporateDomain => 'Official brand website',
            self::RepeatedDomain => 'Website shared by several locations',
            self::SharedPhone => 'Phone number shared by several locations',
            self::SharedSocialAccount => 'Social account shared by several locations',
            self::ParentOrganization => 'Has a parent organization',
            self::BranchOf => 'Declares itself a branch',
            self::FirstPartyChainSource => 'Listed in an official store locator',
            self::CorporatePages => 'Corporate pages (careers, investors, franchise)',
            self::IndependentBusiness => 'Independent business',
            self::SingleLocation => 'Single location',
            self::OwnWebsite => 'Has its own website',
            self::LowDataConfidence => 'Low data confidence',
            self::ConflictingIdentity => 'Conflicting identity data',
            self::InsufficientEvidence => 'Not enough independent evidence',
            self::PermanentlyClosed => 'Permanently closed',
            self::UnknownEntity => 'Entity type could not be determined',
            self::StrongDigitalPresence => 'Strong existing digital presence',
            self::LowBusinessFit => 'Poor fit for our services',
            self::LowOpportunity => 'Few digital gaps to address',
            self::NoContactRoute => 'No way to contact',
            self::NoSellableOpportunity => 'No opportunity we can sell',
            self::MeetsHotCriteria => 'Meets every HOT requirement',
            self::MeetsWarmCriteria => 'Meets the WARM requirements',
        };
    }

    /**
     * @return array{code: string, label: string}
     */
    public function reason(?string $detail = null): array
    {
        return ['code' => $this->value, 'label' => $detail ?? $this->label()];
    }
}

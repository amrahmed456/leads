<?php

namespace App\Enums;

/**
 * A kind of fact about a business that a source can attest.
 */
enum EvidenceFact: string
{
    case Existence = 'existence';
    case Name = 'name';
    case LegalName = 'legal_name';
    case Brand = 'brand';
    case BrandWikidata = 'brand_wikidata';
    case Operator = 'operator';
    case ParentOrganization = 'parent_organization';
    case BranchOf = 'branch_of';
    case Phone = 'phone';
    case Website = 'website';
    case Email = 'email';
    case Social = 'social';
    case Address = 'address';
    case Coordinates = 'coordinates';
    case Category = 'category';
    case OperatingStatus = 'operating_status';
    case OpeningHours = 'opening_hours';
    case CorporatePage = 'corporate_page';

    public function label(): string
    {
        return match ($this) {
            self::Existence => 'Business exists',
            self::Name => 'Name',
            self::LegalName => 'Legal name',
            self::Brand => 'Brand',
            self::BrandWikidata => 'Brand Wikidata ID',
            self::Operator => 'Operator',
            self::ParentOrganization => 'Parent organization',
            self::BranchOf => 'Branch of',
            self::Phone => 'Phone',
            self::Website => 'Website',
            self::Email => 'E-mail',
            self::Social => 'Social profile',
            self::Address => 'Address',
            self::Coordinates => 'Location',
            self::Category => 'Category',
            self::OperatingStatus => 'Operating status',
            self::OpeningHours => 'Opening hours',
            self::CorporatePage => 'Corporate page',
        };
    }

    /**
     * Facts that have one true value: differing values from different source
     * groups are a conflict. A business can have several phones or profiles.
     */
    public function isSingleValued(): bool
    {
        return in_array($this, [self::Name, self::Brand, self::BrandWikidata, self::Website], true);
    }
}

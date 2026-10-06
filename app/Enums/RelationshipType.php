<?php

namespace App\Enums;

/**
 * Edges of the brand / organization graph:
 * Business (branch at a physical location) → Brand → Organization → parent Organization.
 */
enum RelationshipType: string
{
    case BranchOf = 'branch_of';
    case OperatedBy = 'operated_by';
    case OwnedBy = 'owned_by';
    case ParentOrganization = 'parent_organization';

    public function label(): string
    {
        return match ($this) {
            self::BranchOf => 'Branch of',
            self::OperatedBy => 'Operated by',
            self::OwnedBy => 'Owned by',
            self::ParentOrganization => 'Parent organization',
        };
    }
}

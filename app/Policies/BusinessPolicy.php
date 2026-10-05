<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

/**
 * Businesses are created by discovery only; users can browse them.
 */
class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Business $business): bool
    {
        return true;
    }

    /**
     * Manually re-audit the website / re-evaluate the business. Duplicate
     * audits are prevented by the scheduler, not here.
     */
    public function analyze(User $user, Business $business): bool
    {
        return true;
    }
}

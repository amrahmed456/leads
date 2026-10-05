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
}

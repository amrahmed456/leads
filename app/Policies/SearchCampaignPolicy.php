<?php

namespace App\Policies;

use App\Models\SearchCampaign;
use App\Models\User;

/**
 * Single-user application: every authenticated user owns all data. The
 * policy still centralizes the rules so they can tighten later.
 */
class SearchCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SearchCampaign $campaign): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Discovery can be (re)started only when no run is queued or in progress.
     */
    public function run(User $user, SearchCampaign $campaign): bool
    {
        return ! $campaign->status->isActive();
    }
}

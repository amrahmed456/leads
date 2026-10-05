<?php

namespace App\Livewire;

use App\Enums\CampaignStatus;
use App\Models\Business;
use App\Models\SearchCampaign;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', SearchCampaign::class);
        $this->authorize('viewAny', Business::class);
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'businessCount' => Business::count(),
            'campaignCount' => SearchCampaign::count(),
            'activeCampaignCount' => SearchCampaign::whereIn('status', [CampaignStatus::Pending, CampaignStatus::Running])->count(),
            'withoutWebsiteCount' => Business::whereNull('website')->count(),
            'recentCampaigns' => SearchCampaign::latest()->latest('id')->limit(5)->get(),
            'recentBusinesses' => Business::with('sources')->latest('first_discovered_at')->latest('id')->limit(10)->get(),
        ]);
    }
}

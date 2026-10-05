<?php

namespace App\Livewire;

use App\Enums\AuditStatus;
use App\Enums\CampaignStatus;
use App\Enums\LeadGrade;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use App\Models\WebsiteAudit;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    private const TABLE_RELATIONS = ['sources', 'leadScore', 'opportunities'];

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
            'activeAuditCount' => WebsiteAudit::whereIn('status', [AuditStatus::Pending, AuditStatus::Running])->count(),
            'hotLeadCount' => LeadScore::where('grade', LeadGrade::Hot)->count(),
            'withoutWebsiteCount' => Business::whereNull('website')->count(),
            'recentCampaigns' => SearchCampaign::latest()->latest('id')->limit(5)->get(),
            'topLeads' => Business::with(self::TABLE_RELATIONS)
                ->whereHas('leadScore')
                ->orderByDesc(LeadScore::select('score')->whereColumn('lead_scores.business_id', 'businesses.id'))
                ->orderBy('name')
                ->limit(10)
                ->get(),
            'recentBusinesses' => Business::with(self::TABLE_RELATIONS)->latest('first_discovered_at')->latest('id')->limit(10)->get(),
        ]);
    }
}

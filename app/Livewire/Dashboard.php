<?php

namespace App\Livewire;

use App\Enums\AuditStatus;
use App\Enums\CampaignStatus;
use App\Enums\EntityType;
use App\Enums\EvidenceFact;
use App\Enums\LeadStatus;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\BusinessSource;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use App\Models\WebsiteAudit;
use App\Sources\SourceRegistry;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    private const TABLE_RELATIONS = ['sources', 'leadScore', 'opportunities', 'brand'];

    public function mount(): void
    {
        $this->authorize('viewAny', SearchCampaign::class);
        $this->authorize('viewAny', Business::class);
    }

    public function render(SourceRegistry $sources): View
    {
        $businessCount = Business::count();
        $statusCounts = LeadScore::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total);

        return view('livewire.dashboard', [
            'businessCount' => $businessCount,
            'campaignCount' => SearchCampaign::count(),
            'activeCampaignCount' => SearchCampaign::whereIn('status', [CampaignStatus::Pending, CampaignStatus::Running])->count(),
            'activeAuditCount' => WebsiteAudit::whereIn('status', [AuditStatus::Pending, AuditStatus::Running])->count(),
            'withWebsiteCount' => Business::whereNotNull('website')->count(),
            'withoutWebsiteCount' => Business::whereNull('website')->count(),
            'statusCounts' => $statusCounts,
            'entityCounts' => Business::query()->whereNotNull('entity_type')->selectRaw('entity_type, COUNT(*) as total')->groupBy('entity_type')->pluck('total', 'entity_type')->map(fn (mixed $total): int => (int) $total),
            'rejectedChainCount' => $this->rejectedChainCount(),
            'sourceContribution' => $this->sourceContribution($sources),
            'recentCampaigns' => SearchCampaign::latest()->latest('id')->limit(5)->get(),
            'topLeads' => Business::with(self::TABLE_RELATIONS)
                ->whereHas('leadScore', fn ($score) => $score->whereIn('status', [LeadStatus::Hot, LeadStatus::Warm]))
                ->orderByDesc(LeadScore::select('score')->whereColumn('lead_scores.business_id', 'businesses.id'))
                ->orderBy('name')
                ->limit(10)
                ->get(),
            'recentBusinesses' => Business::with(self::TABLE_RELATIONS)->latest('first_discovered_at')->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Businesses disqualified because they were recognized as chains,
     * enterprises or corporate branches: the false hot leads avoided.
     */
    private function rejectedChainCount(): int
    {
        return Business::query()
            ->whereIn('entity_type', array_map(fn (EntityType $type): string => $type->value, EntityType::chainKinds()))
            ->whereHas('leadScore', fn ($score) => $score->where('status', LeadStatus::Disqualified))
            ->count();
    }

    /**
     * Businesses each source contributed evidence to.
     *
     * @return Collection<int, array{key: string, label: string, businesses: int, group: string}>
     */
    private function sourceContribution(SourceRegistry $sources): Collection
    {
        $counts = BusinessSource::query()
            ->selectRaw('provider, COUNT(DISTINCT business_id) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->map(fn (mixed $total): int => (int) $total);

        $counts['website'] = BusinessEvidence::query()
            ->where('provider', 'website')
            ->where('fact_type', EvidenceFact::Existence)
            ->distinct()
            ->count('business_id');

        return collect(['openstreetmap', 'overture', 'alltheplaces', 'geoapify', 'website'])
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => $sources->label($key),
                'businesses' => $counts[$key] ?? 0,
                'group' => $sources->independenceGroup($key),
            ]);
    }
}

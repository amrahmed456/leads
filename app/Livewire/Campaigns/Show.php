<?php

namespace App\Livewire\Campaigns;

use App\Jobs\RunCampaignDiscovery;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use App\Models\WebsiteAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public SearchCampaign $campaign;

    public function mount(SearchCampaign $campaign): void
    {
        $this->authorize('view', $campaign);

        $this->campaign = $campaign;
    }

    public function startDiscovery(): void
    {
        $this->campaign->refresh();

        $this->authorize('run', $this->campaign);

        $this->campaign->markQueued();

        RunCampaignDiscovery::dispatch($this->campaign);

        session()->flash('status', 'Discovery queued.');

        $this->redirectRoute('campaigns.show', $this->campaign);
    }

    public function render(): View
    {
        return view('livewire.campaigns.show', [
            'businesses' => $this->campaign->businesses()
                ->with(['sources', 'leadScore', 'opportunities.websiteAudit'])
                ->orderByDesc(LeadScore::select('score')->whereColumn('lead_scores.business_id', 'businesses.id'))
                ->orderBy('name')
                ->paginate(25),
            'auditCounts' => $this->auditCounts(),
        ])->title($this->campaign->name);
    }

    /**
     * Status of the latest website audit of each business in the campaign.
     *
     * @return Collection<string, int>
     */
    private function auditCounts(): Collection
    {
        $businessIds = DB::table('business_search_campaign')
            ->select('business_id')
            ->where('search_campaign_id', $this->campaign->getKey());

        $latestAuditIds = WebsiteAudit::query()
            ->selectRaw('MAX(id)')
            ->whereIn('business_id', $businessIds)
            ->groupBy('business_id');

        return WebsiteAudit::query()
            ->whereIn('id', $latestAuditIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total);
    }
}

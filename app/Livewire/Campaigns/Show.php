<?php

namespace App\Livewire\Campaigns;

use App\Jobs\RunCampaignDiscovery;
use App\Models\SearchCampaign;
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
                ->with('sources')
                ->orderBy('name')
                ->paginate(25),
        ])->title($this->campaign->name);
    }
}

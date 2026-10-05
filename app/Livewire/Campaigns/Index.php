<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\SearchCampaign;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Search campaigns')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SearchCampaign::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $campaigns = SearchCampaign::query()
            ->when(CampaignStatus::tryFrom($this->status), fn ($query, CampaignStatus $status) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(20);

        return view('livewire.campaigns.index', [
            'campaigns' => $campaigns,
            'statuses' => CampaignStatus::cases(),
            'hasActive' => $campaigns->getCollection()->contains(fn (SearchCampaign $campaign) => $campaign->status->isActive()),
        ]);
    }
}

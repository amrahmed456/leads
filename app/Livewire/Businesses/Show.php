<?php

namespace App\Livewire\Businesses;

use App\Models\Business;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    public Business $business;

    public function mount(Business $business): void
    {
        $this->authorize('view', $business);

        $this->business = $business->load([
            'sources' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
            'campaigns' => fn ($query) => $query->latest(),
        ]);
    }

    public function render(): View
    {
        return view('livewire.businesses.show')->title($this->business->name);
    }
}

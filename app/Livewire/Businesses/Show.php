<?php

namespace App\Livewire\Businesses;

use App\Auditing\WebsiteAuditScheduler;
use App\Intelligence\BusinessEvaluator;
use App\Intelligence\Outreach\OutreachMessageComposer;
use App\Models\Business;
use App\Models\Opportunity;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    public Business $business;

    public function mount(Business $business): void
    {
        $this->authorize('view', $business);

        $this->business = $business;
    }

    /**
     * Re-audit the website now (bypassing freshness), or, for a business
     * without a website, re-evaluate it immediately.
     */
    public function analyze(WebsiteAuditScheduler $scheduler, BusinessEvaluator $evaluator): void
    {
        $this->business->refresh();

        $this->authorize('analyze', $this->business);

        $audit = $scheduler->queue($this->business, force: true);

        if ($this->business->website === null) {
            $evaluator->evaluate($this->business);
            session()->flash('status', 'Business re-evaluated.');
        } else {
            session()->flash('status', $audit !== null ? 'Website audit queued.' : 'An audit of this website is already queued or running.');
        }

        $this->redirectRoute('businesses.show', $this->business);
    }

    public function render(BusinessEvaluator $evaluator, OutreachMessageComposer $outreach): View
    {
        $this->business->load([
            'sources' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
            'campaigns' => fn ($query) => $query->latest(),
            'opportunities.websiteAudit',
            'leadScore',
        ]);

        return view('livewire.businesses.show', [
            'currentAudit' => $this->business->websiteAudits()->latest('id')->first(),
            'audit' => $evaluator->relevantAudit($this->business),
            'opportunities' => $this->business->opportunities
                ->sortByDesc(fn (Opportunity $opportunity): int => $opportunity->priority->rank())
                ->values(),
            'outreach' => $outreach->compose($this->business),
            'senderConfigured' => filled(config('intelligence.outreach.sender_name')),
        ])->title($this->business->name);
    }
}

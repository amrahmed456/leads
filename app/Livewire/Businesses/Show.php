<?php

namespace App\Livewire\Businesses;

use App\Auditing\WebsiteAuditScheduler;
use App\Enums\EvidenceStatus;
use App\Enums\RelationshipType;
use App\Intelligence\BusinessEvaluator;
use App\Models\Business;
use App\Models\BusinessEvidence;
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

    /**
     * Re-run identity analysis and lead qualification without re-auditing.
     */
    public function reevaluate(BusinessEvaluator $evaluator): void
    {
        $this->business->refresh();

        $this->authorize('analyze', $this->business);

        $evaluator->evaluate($this->business);

        session()->flash('status', 'Identity and lead qualification recalculated.');

        $this->redirectRoute('businesses.show', $this->business);
    }

    public function render(BusinessEvaluator $evaluator): View
    {
        $this->business->load([
            'sources' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id'),
            'campaigns' => fn ($query) => $query->latest(),
            'opportunities',
            'leadScore',
            'brand.organization',
            'evidence' => fn ($query) => $query->orderBy('fact_type')->orderBy('status')->orderBy('provider'),
            'relationships.object',
        ]);

        $evidence = $this->business->evidence;

        return view('livewire.businesses.show', [
            'currentAudit' => $this->business->websiteAudits()->latest('id')->first(),
            'audit' => $evaluator->relevantAudit($this->business),
            'opportunities' => $this->business->opportunities
                ->sortByDesc(fn (Opportunity $opportunity): int => $opportunity->priority->rank())
                ->values(),
            'evidenceByFact' => $evidence->groupBy(fn (BusinessEvidence $item): string => $item->fact_type->label()),
            'evidenceGroups' => $evidence->where('status', '!=', EvidenceStatus::Stale)->pluck('independence_group')->unique()->values(),
            'parentOrganization' => $this->business->relationships->firstWhere('relationship', RelationshipType::ParentOrganization)?->object,
            'branchOf' => $this->business->relationships->firstWhere('relationship', RelationshipType::BranchOf),
        ])->title($this->business->name);
    }
}

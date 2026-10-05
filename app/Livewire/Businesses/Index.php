<?php

namespace App\Livewire\Businesses;

use App\Discovery\DiscoveryProviderRegistry;
use App\Enums\BusinessCategory;
use App\Enums\LeadGrade;
use App\Enums\OpportunityType;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Businesses')]
class Index extends Component
{
    use WithPagination;

    public const SORTS = [
        'newest' => 'Newest first',
        'lead_score' => 'Highest lead score',
        'name' => 'Name (A–Z)',
        'name_desc' => 'Name (Z–A)',
        'rating' => 'Highest rating',
        'reviews' => 'Most reviews',
    ];

    private const FILTERS = ['search', 'campaign', 'provider', 'city', 'category', 'website', 'phone', 'opportunity', 'grade', 'sort'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $campaign = '';

    #[Url(except: '')]
    public string $provider = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $category = '';

    /** "", "with" or "without" */
    #[Url(except: '')]
    public string $website = '';

    /** "", "with" or "without" */
    #[Url(except: '')]
    public string $phone = '';

    #[Url(except: '')]
    public string $opportunity = '';

    #[Url(except: '')]
    public string $grade = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->authorize('viewAny', Business::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    public function render(): View
    {
        $registry = app(DiscoveryProviderRegistry::class);

        return view('livewire.businesses.index', [
            'businesses' => $this->query()->with(['sources', 'leadScore', 'opportunities'])->paginate(25),
            'campaigns' => SearchCampaign::query()->latest()->pluck('name', 'id'),
            'providers' => BusinessSource::query()->distinct()->orderBy('provider')->pluck('provider')
                ->mapWithKeys(fn (string $provider) => [$provider => $registry->label($provider)]),
            'cities' => Business::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'categories' => BusinessCategory::options(),
            'opportunityTypes' => OpportunityType::options(),
            'grades' => LeadGrade::cases(),
            'sorts' => self::SORTS,
            'filtered' => collect(self::FILTERS)->contains(fn (string $filter) => $this->{$filter} !== ($filter === 'sort' ? 'newest' : '')),
        ]);
    }

    /**
     * @return Builder<Business>
     */
    private function query(): Builder
    {
        $query = Business::query()
            ->search($this->search)
            ->when(ctype_digit($this->campaign), fn (Builder $query) => $query->whereHas(
                'campaigns', fn (Builder $campaigns) => $campaigns->whereKey((int) $this->campaign),
            ))
            ->when($this->provider !== '', fn (Builder $query) => $query->whereHas(
                'sources', fn (Builder $sources) => $sources->where('provider', $this->provider),
            ))
            ->when($this->city !== '', fn (Builder $query) => $query->where('city', $this->city))
            ->when(BusinessCategory::tryFrom($this->category), fn (Builder $query, BusinessCategory $category) => $query->where('category', $category))
            ->when($this->website === 'with', fn (Builder $query) => $query->whereNotNull('website'))
            ->when($this->website === 'without', fn (Builder $query) => $query->whereNull('website'))
            ->when($this->phone === 'with', fn (Builder $query) => $query->whereNotNull('phone'))
            ->when($this->phone === 'without', fn (Builder $query) => $query->whereNull('phone'))
            ->when(OpportunityType::tryFrom($this->opportunity), fn (Builder $query, OpportunityType $type) => $query->whereHas(
                'opportunities', fn (Builder $opportunities) => $opportunities->where('type', $type),
            ))
            ->when(LeadGrade::tryFrom($this->grade), fn (Builder $query, LeadGrade $grade) => $query->whereHas(
                'leadScore', fn (Builder $score) => $score->where('grade', $grade),
            ));

        // Businesses without a rating/review count are listed last.
        return match ($this->sort) {
            'lead_score' => $query->orderByDesc(LeadScore::select('score')->whereColumn('lead_scores.business_id', 'businesses.id'))->orderBy('name'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'rating' => $query->orderByRaw('rating IS NULL')->orderByDesc('rating')->orderBy('name'),
            'reviews' => $query->orderByRaw('reviews_count IS NULL')->orderByDesc('reviews_count')->orderBy('name'),
            default => $query->latest('first_discovered_at')->latest('id'),
        };
    }
}

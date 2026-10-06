<?php

namespace App\Livewire\Businesses;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityType;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use App\Sources\SourceRegistry;
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
        'lead_score' => 'Highest lead score',
        'opportunity' => 'Highest opportunity',
        'confidence' => 'Highest data confidence',
        'fit' => 'Best business fit',
        'evidence' => 'Most independent evidence',
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'name' => 'Name (A–Z)',
        'name_desc' => 'Name (Z–A)',
        'rating' => 'Highest rating',
        'reviews' => 'Most reviews',
    ];

    /** "Has ..." filters on the latest website audit: value => [column, expected]. */
    public const FEATURES = [
        'whatsapp' => ['has_whatsapp', true, 'Has WhatsApp'],
        'no_whatsapp' => ['has_whatsapp', false, 'No WhatsApp'],
        'booking' => ['has_booking', true, 'Has online booking'],
        'no_booking' => ['has_booking', false, 'No online booking'],
        'ordering' => ['has_online_ordering', true, 'Has online ordering'],
        'no_ordering' => ['has_online_ordering', false, 'No online ordering'],
        'ecommerce' => ['has_cart', true, 'Has e-commerce checkout'],
        'no_ecommerce' => ['has_cart', false, 'No e-commerce checkout'],
        'arabic' => ['has_arabic', true, 'Arabic website'],
        'no_arabic' => ['has_arabic', false, 'No Arabic on website'],
        'english' => ['has_english', true, 'English website'],
        'no_english' => ['has_english', false, 'No English on website'],
    ];

    private const FILTERS = [
        'search', 'campaign', 'provider', 'city', 'category', 'website', 'phone', 'opportunity', 'status', 'entity',
        'brand', 'minConfidence', 'minFit', 'minOpportunity', 'minEvidence', 'feature', 'hideDisqualified', 'sort',
    ];

    private const DEFAULTS = ['sort' => 'lead_score', 'hideDisqualified' => true];

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

    /** A LeadStatus value. */
    #[Url(except: '')]
    public string $status = '';

    /** An EntityType value, or "independent" / "chain" for the whole group. */
    #[Url(except: '')]
    public string $entity = '';

    #[Url(except: '')]
    public string $brand = '';

    #[Url(as: 'confidence', except: '')]
    public string $minConfidence = '';

    #[Url(as: 'fit', except: '')]
    public string $minFit = '';

    #[Url(as: 'opp', except: '')]
    public string $minOpportunity = '';

    #[Url(as: 'evidence', except: '')]
    public string $minEvidence = '';

    #[Url(except: '')]
    public string $feature = '';

    #[Url(as: 'hide_disqualified', except: true)]
    public bool $hideDisqualified = true;

    #[Url(except: 'lead_score')]
    public string $sort = 'lead_score';

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

    public function render(SourceRegistry $sources): View
    {
        return view('livewire.businesses.index', [
            'businesses' => $this->query()->with(['sources', 'leadScore', 'opportunities', 'brand'])->paginate(25),
            'campaigns' => SearchCampaign::query()->latest()->pluck('name', 'id'),
            'providers' => BusinessSource::query()->distinct()->orderBy('provider')->pluck('provider')
                ->mapWithKeys(fn (string $provider) => [$provider => $sources->label($provider)]),
            'cities' => Business::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'brands' => Brand::query()->whereHas('businesses')->orderBy('name')->pluck('name', 'id'),
            'categories' => BusinessCategory::options(),
            'opportunityTypes' => OpportunityType::options(),
            'statuses' => LeadStatus::options(),
            'entityTypes' => EntityType::options(),
            'features' => array_map(fn (array $feature): string => $feature[2], self::FEATURES),
            'sorts' => self::SORTS,
            'filtered' => collect(self::FILTERS)->contains(fn (string $filter) => $this->{$filter} !== (self::DEFAULTS[$filter] ?? '')),
        ]);
    }

    /**
     * @return Builder<Business>
     */
    private function query(): Builder
    {
        $score = fn (string $column) => LeadScore::select($column)->whereColumn('lead_scores.business_id', 'businesses.id');

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
            ->when(ctype_digit($this->brand), fn (Builder $query) => $query->where('brand_id', (int) $this->brand))
            ->when($this->minEvidence !== '' && ctype_digit($this->minEvidence), fn (Builder $query) => $query->where('independent_source_count', '>=', (int) $this->minEvidence));

        $this->filterEntity($query);
        $this->filterScores($query);
        $this->filterFeature($query);

        return match ($this->sort) {
            'opportunity' => $query->orderByDesc($score('opportunity_score'))->orderBy('name'),
            'confidence' => $query->orderByDesc($score('data_confidence_score'))->orderBy('name'),
            'fit' => $query->orderByDesc($score('business_fit_score'))->orderBy('name'),
            'evidence' => $query->orderByDesc('independent_source_count')->orderByDesc('evidence_count')->orderBy('name'),
            'newest' => $query->latest('first_discovered_at')->latest('id'),
            'oldest' => $query->oldest('first_discovered_at')->oldest('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            // Businesses without a rating/review count are listed last.
            'rating' => $query->orderByRaw('rating IS NULL')->orderByDesc('rating')->orderBy('name'),
            'reviews' => $query->orderByRaw('reviews_count IS NULL')->orderByDesc('reviews_count')->orderBy('name'),
            default => $query->orderByDesc($score('score'))->orderBy('name'),
        };
    }

    /**
     * @param  Builder<Business>  $query
     */
    private function filterEntity(Builder $query): void
    {
        $types = match ($this->entity) {
            'independent' => EntityType::independentKinds(),
            'chain' => EntityType::chainKinds(),
            default => array_filter([EntityType::tryFrom($this->entity)]),
        };

        if ($types !== []) {
            $query->whereIn('entity_type', array_map(fn (EntityType $type): string => $type->value, $types));
        }
    }

    /**
     * @param  Builder<Business>  $query
     */
    private function filterScores(Builder $query): void
    {
        $status = LeadStatus::tryFrom($this->status);
        $minimums = array_filter([
            'data_confidence_score' => $this->minConfidence,
            'business_fit_score' => $this->minFit,
            'opportunity_score' => $this->minOpportunity,
        ], fn (string $value): bool => $value !== '' && ctype_digit($value));

        if ($status === null && $minimums === [] && ! $this->hideDisqualified) {
            return;
        }

        $constrain = function (Builder $score) use ($status, $minimums): void {
            $score->when($status, fn (Builder $score, LeadStatus $status) => $score->where('status', $status))
                ->when($status === null && $this->hideDisqualified, fn (Builder $score) => $score->where('status', '!=', LeadStatus::Disqualified));

            foreach ($minimums as $column => $minimum) {
                $score->where($column, '>=', (int) $minimum);
            }
        };

        // Hiding disqualified businesses keeps those not scored yet.
        if ($status === null && $minimums === []) {
            $query->whereDoesntHave('leadScore', fn (Builder $score) => $score->where('status', LeadStatus::Disqualified));

            return;
        }

        $query->whereHas('leadScore', $constrain);
    }

    /**
     * @param  Builder<Business>  $query
     */
    private function filterFeature(Builder $query): void
    {
        [$column, $expected] = self::FEATURES[$this->feature] ?? [null, null];

        if ($column !== null) {
            $query->whereHas('latestWebsiteAudit', fn (Builder $audit) => $audit->where($column, $expected));
        }
    }
}

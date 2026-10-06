<?php

namespace App\Livewire\Campaigns;

use App\Discovery\DiscoveryProviderRegistry;
use App\Enums\BusinessCategory;
use App\Enums\Country;
use App\Enums\SourceRole;
use App\Jobs\RunCampaignDiscovery;
use App\Models\SearchCampaign;
use App\Sources\GeoNames\CityResolver;
use App\Sources\SourceRegistry;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New search campaign')]
class Create extends Component
{
    private const MAX_KEYWORDS = 10;

    public string $name = '';

    public string $preset = '';

    /** @var list<string> Discovery sources to query */
    public array $sources = [];

    public string $country = 'SA';

    public string $city = '';

    public string $category = '';

    public string $keywords = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $radiusKm = '';

    public function mount(): void
    {
        $this->authorize('create', SearchCampaign::class);

        $this->usePreset((string) config('sources.default_preset'));
        $this->radiusKm = (string) config('discovery.radius.default_km');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'preset' => ['nullable', 'string', Rule::in(array_keys((array) config('sources.presets')))],
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['string', 'distinct', Rule::in(array_keys(app(DiscoveryProviderRegistry::class)->options()))],
            'country' => ['required', Rule::enum(Country::class)],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'category' => ['required', Rule::enum(BusinessCategory::class)],
            'keywords' => ['nullable', 'string', 'max:500', $this->keywordsRule()],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'radiusKm' => [
                'required', 'numeric',
                'min:'.config('discovery.radius.min_km'),
                'max:'.config('discovery.radius.max_km'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['radiusKm' => 'radius', 'sources' => 'discovery sources', 'sources.*' => 'discovery source'];
    }

    /**
     * Selecting a preset selects its sources (those available; paid ones never are by default).
     */
    public function updatedPreset(string $preset): void
    {
        $this->usePreset($preset);
    }

    private function usePreset(string $preset): void
    {
        $available = array_keys(app(DiscoveryProviderRegistry::class)->options());
        $this->preset = array_key_exists($preset, (array) config('sources.presets')) ? $preset : '';
        $this->sources = array_values(array_intersect((array) config("sources.presets.{$this->preset}.discovery", []), $available)) ?: array_slice($available, 0, 1);
    }

    public function save(CityResolver $cities): void
    {
        $this->authorize('create', SearchCampaign::class);

        $this->validate();

        $category = BusinessCategory::from($this->category);
        $country = Country::from($this->country);
        $city = trim((string) preg_replace('/\s+/u', ' ', $this->city));
        // "الرياض" and "Riyadh" become the same campaign city when GeoNames knows it.
        $city = $cities->canonicalName($city, $country) ?? $city;
        $sources = array_values(array_unique($this->sources));

        $campaign = SearchCampaign::create([
            'name' => trim($this->name) !== '' ? trim($this->name) : "{$category->label()} in {$city}",
            'provider' => $sources[0],
            'sources' => $sources,
            'preset' => $this->preset !== '' ? $this->preset : null,
            'country_code' => $country,
            'city' => $city,
            'latitude' => $this->latitude !== '' ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== '' ? (float) $this->longitude : null,
            'radius_meters' => (int) round((float) $this->radiusKm * 1000),
            'category' => $category,
            'keywords' => SearchCampaign::parseKeywords($this->keywords) ?: null,
        ]);

        RunCampaignDiscovery::dispatch($campaign);

        session()->flash('status', 'Campaign created and discovery queued.');

        $this->redirectRoute('campaigns.show', $campaign);
    }

    public function render(): View
    {
        return view('livewire.campaigns.create', [
            'discoverySources' => $this->discoverySources(),
            'presets' => (array) config('sources.presets'),
            'countries' => Country::options(),
            'categories' => BusinessCategory::options(),
            'citySuggestions' => Country::tryFrom($this->country)?->suggestedCities() ?? [],
            'radius' => config('discovery.radius'),
        ]);
    }

    /**
     * Every discovery source, including unavailable and future ones, with why it cannot be used.
     *
     * @return list<array{key: string, label: string, available: bool, reason: ?string, tier: string, group: string, notes: ?string}>
     */
    private function discoverySources(): array
    {
        $registry = app(DiscoveryProviderRegistry::class);
        $catalog = app(SourceRegistry::class);
        $keys = array_values(array_unique([...$registry->keys(), ...array_keys($catalog->withRole(SourceRole::Discovery))]));

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $catalog->label($key),
            'available' => $registry->isEnabled($key),
            'reason' => $registry->isEnabled($key) ? null : ($catalog->has($key) && ! $catalog->descriptor($key)->implemented ? 'Coming later' : $registry->unavailableReason($key)),
            'tier' => $catalog->has($key) ? $catalog->descriptor($key)->tier : 'free',
            'group' => $catalog->independenceGroup($key),
            'notes' => $catalog->has($key) ? $catalog->descriptor($key)->notes : null,
        ], $keys);
    }

    /**
     * Keywords end up in an Overpass regular expression, so only plain words
     * are accepted (letters in any script, digits, spaces, - ' &).
     */
    private function keywordsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $keywords = SearchCampaign::parseKeywords((string) $value);

            if (count($keywords) > self::MAX_KEYWORDS) {
                $fail('Use at most '.self::MAX_KEYWORDS.' keywords.');

                return;
            }

            foreach ($keywords as $keyword) {
                if (mb_strlen($keyword) < 2 || mb_strlen($keyword) > 50) {
                    $fail("Each keyword must be 2–50 characters long (\"{$keyword}\").");

                    return;
                }

                if (! preg_match("/^[\p{L}\p{M}\p{N} '&-]+$/u", $keyword)) {
                    $fail("Keywords may only contain letters, numbers, spaces, hyphens, apostrophes and & (\"{$keyword}\").");

                    return;
                }
            }
        };
    }
}

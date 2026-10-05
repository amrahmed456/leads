<?php

namespace App\Livewire\Campaigns;

use App\Discovery\DiscoveryProviderRegistry;
use App\Enums\BusinessCategory;
use App\Enums\Country;
use App\Jobs\RunCampaignDiscovery;
use App\Models\SearchCampaign;
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

    public string $provider = '';

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

        $this->provider = (string) config('discovery.default_provider');
        $this->radiusKm = (string) config('discovery.radius.default_km');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'provider' => ['required', 'string', Rule::in(array_keys(app(DiscoveryProviderRegistry::class)->options()))],
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
        return ['radiusKm' => 'radius'];
    }

    public function save(): void
    {
        $this->authorize('create', SearchCampaign::class);

        $this->validate();

        $category = BusinessCategory::from($this->category);
        $city = trim((string) preg_replace('/\s+/u', ' ', $this->city));

        $campaign = SearchCampaign::create([
            'name' => trim($this->name) !== '' ? trim($this->name) : "{$category->label()} in {$city}",
            'provider' => $this->provider,
            'country_code' => Country::from($this->country),
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
            'providers' => app(DiscoveryProviderRegistry::class)->options(),
            'countries' => Country::options(),
            'categories' => BusinessCategory::options(),
            'citySuggestions' => Country::tryFrom($this->country)?->suggestedCities() ?? [],
            'radius' => config('discovery.radius'),
        ]);
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

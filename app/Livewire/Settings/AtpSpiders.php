<?php

namespace App\Livewire\Settings;

use App\Enums\Country;
use App\Jobs\SyncAllThePlacesSpider;
use App\Models\AtpSpider;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → AllThePlaces → Brand spiders: the brands whose official store
 * locators are synced as first-party branch evidence. Only these spiders
 * are ever downloaded.
 */
#[Title('AllThePlaces spiders')]
class AtpSpiders extends Component
{
    public string $spider = '';

    public string $brandName = '';

    public string $brandWikidata = '';

    public string $country = '';

    public string $notes = '';

    public ?int $showResult = null;

    public function mount(): void
    {
        $this->authorize('manage-data-sources');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'spider' => ['required', 'string', 'regex:/^[a-z0-9_]{2,100}$/', Rule::unique('atp_spiders', 'spider')],
            'brandName' => ['required', 'string', 'max:150'],
            'brandWikidata' => ['nullable', 'regex:/^Q\d{1,12}$/'],
            'country' => ['nullable', Rule::enum(Country::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'spider.regex' => 'Use the spider name from AllThePlaces: lower-case letters, digits and underscores (e.g. kfc).',
            'brandWikidata.regex' => 'A Wikidata ID looks like Q524757.',
        ];
    }

    public function add(): void
    {
        $this->authorize('manage-data-sources');
        $this->validate();

        AtpSpider::query()->create([
            'spider' => $this->spider,
            'brand_name' => trim($this->brandName),
            'brand_wikidata' => $this->brandWikidata !== '' ? $this->brandWikidata : null,
            'country_code' => $this->country !== '' ? $this->country : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->reset(['spider', 'brandName', 'brandWikidata', 'country', 'notes']);
        session()->flash('status', 'Spider added. Run a sync to fetch its branches.');
        $this->redirectRoute('settings.alltheplaces');
    }

    public function toggle(int $id): void
    {
        $this->authorize('manage-data-sources');

        $spider = AtpSpider::query()->findOrFail($id);
        $spider->forceFill(['enabled' => ! $spider->enabled])->save();
    }

    public function sync(int $id): void
    {
        $this->authorize('manage-data-sources');

        $spider = AtpSpider::query()->where('enabled', true)->findOrFail($id);
        SyncAllThePlacesSpider::dispatch($spider);

        session()->flash('status', "Sync of {$spider->spider} queued.");
        $this->redirectRoute('settings.alltheplaces');
    }

    public function remove(int $id): void
    {
        $this->authorize('manage-data-sources');

        $spider = AtpSpider::query()->findOrFail($id);
        $spider->places()->delete();
        $spider->delete();

        session()->flash('status', 'Spider and its stored branches removed.');
        $this->redirectRoute('settings.alltheplaces');
    }

    public function render(): View
    {
        return view('livewire.settings.atp-spiders', [
            'spiders' => AtpSpider::query()->orderBy('brand_name')->get(),
            'countries' => Country::options(),
        ]);
    }
}

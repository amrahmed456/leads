<?php

namespace App\Livewire\Settings;

use App\Jobs\RefreshBrandRegistry;
use App\Models\AddressPoint;
use App\Models\AtpSpider;
use App\Models\Brand;
use App\Models\GeoPlace;
use App\Models\SourcePlace;
use App\Sources\QuotaManager;
use App\Sources\SecretScrubber;
use App\Sources\SourceDescriptor;
use App\Sources\SourceRegistry;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Data Sources: enable sources, store their credentials
 * (encrypted, write-only), set daily budgets and watch health and usage.
 *
 * Credentials are never placed in a public property or the page: inputs
 * start empty and only masked values are displayed.
 */
#[Title('Data sources')]
class DataSources extends Component
{
    /** @var array<string, array<string, string>> New credential values typed by the admin, per source */
    public array $credentialInput = [];

    /** @var array<string, string> Daily quota overrides, per source */
    public array $quotaInput = [];

    public function mount(SourceRegistry $sources): void
    {
        $this->authorize('manage-data-sources');

        foreach ($sources->all() as $key => $source) {
            $this->quotaInput[$key] = (string) ($sources->setting($key, 'daily_quota') ?? '');
        }
    }

    public function toggle(string $key, SourceRegistry $sources): void
    {
        $this->authorize('manage-data-sources');

        if (! $sources->has($key) || ! $sources->descriptor($key)->implemented) {
            return;
        }

        $sources->setEnabled($key, ! $sources->isEnabled($key));
    }

    public function saveCredentials(string $key, SourceRegistry $sources): void
    {
        $this->authorize('manage-data-sources');

        if (! $sources->has($key)) {
            return;
        }

        $fields = array_keys($sources->descriptor($key)->credentials);
        $this->validate(array_fill_keys(array_map(fn (string $field): string => "credentialInput.{$key}.{$field}", $fields), ['nullable', 'string', 'max:500']));

        $sources->saveCredentials($key, array_intersect_key($this->credentialInput[$key] ?? [], array_flip($fields)));
        $this->credentialInput[$key] = [];

        $this->done("{$sources->label($key)} credentials saved (encrypted).");
    }

    public function clearCredentials(string $key, SourceRegistry $sources): void
    {
        $this->authorize('manage-data-sources');

        if ($sources->has($key)) {
            $sources->clearCredentials($key);
            $this->done("{$sources->label($key)} credentials removed.");
        }
    }

    public function saveQuota(string $key, SourceRegistry $sources): void
    {
        $this->authorize('manage-data-sources');
        $this->validate(["quotaInput.{$key}" => ['nullable', 'integer', 'min:0', 'max:10000000']]);

        if (! $sources->has($key)) {
            return;
        }

        $state = $sources->state($key);
        $settings = $state->settings ?? [];
        $value = $this->quotaInput[$key] ?? '';

        if ($value === '' || $value === null) {
            unset($settings['daily_quota']);
        } else {
            $settings['daily_quota'] = (int) $value;
        }

        $state->settings = $settings === [] ? null : $settings;
        $state->save();

        $this->done("{$sources->label($key)} daily budget saved.");
    }

    public function refreshBrands(): void
    {
        $this->authorize('manage-data-sources');

        RefreshBrandRegistry::dispatch();

        $this->done('Brand registry refresh queued.');
    }

    public function render(SourceRegistry $sources, QuotaManager $quotas): View
    {
        $rows = [];

        foreach ($sources->all() as $key => $source) {
            $state = $sources->state($key);

            $rows[] = [
                'key' => $key,
                'source' => $source,
                'enabled' => $sources->isEnabled($key),
                'configured' => $sources->isConfigured($key),
                'health' => $sources->health($key, $quotas),
                'state' => $state,
                'used' => $quotas->used($key),
                'limit' => $quotas->dailyLimit($key),
                'remaining' => $quotas->remaining($key),
                // Masked only; the real value never reaches the browser.
                'credentials' => array_map(
                    fn (string $field): ?string => SecretScrubber::mask($sources->credential($key, $field)),
                    array_combine(array_keys($source->credentials), array_keys($source->credentials)),
                ),
                'stats' => $this->stats($key),
            ];
        }

        return view('livewire.settings.data-sources', [
            'free' => array_values(array_filter($rows, fn (array $row): bool => $row['source']->implemented)),
            'future' => array_values(array_filter($rows, fn (array $row): bool => ! $row['source']->implemented)),
        ]);
    }

    private function stats(string $key): ?string
    {
        return match ($key) {
            'overture' => number_format(SourcePlace::query()->where('source', 'overture')->count()).' places imported',
            'alltheplaces' => number_format(AtpSpider::query()->where('enabled', true)->count()).' spiders · '.number_format(SourcePlace::query()->where('source', 'alltheplaces')->count()).' branches stored',
            'nsi' => number_format(Brand::query()->whereNotNull('nsi_id')->count()).' registered brands',
            'wikidata' => number_format(Brand::query()->whereNotNull('wikidata_fetched_at')->count()).' brands enriched',
            'geonames' => number_format(GeoPlace::query()->count()).' cities',
            'openaddresses' => number_format(AddressPoint::query()->count()).' address points',
            default => null,
        };
    }

    private function done(string $message): void
    {
        session()->flash('status', $message);

        $this->redirectRoute('settings.sources');
    }

    /**
     * @return list<string>
     */
    public static function roleLabels(SourceDescriptor $source): array
    {
        return array_map(fn ($role): string => $role->label(), $source->roles);
    }
}

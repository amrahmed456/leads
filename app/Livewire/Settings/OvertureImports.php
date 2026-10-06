<?php

namespace App\Livewire\Settings;

use App\Enums\Country;
use App\Enums\ImportStatus;
use App\Jobs\ImportOvertureExtract;
use App\Models\OvertureImport;
use App\Sources\Overture\OvertureDuckDb;
use App\Sources\Overture\OvertureExtractImporter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Settings → Overture → Imports: bounded geographic extracts of Overture
 * Places, uploaded, fetched from a small remote URL, or (optionally)
 * extracted with DuckDB. Imports run in resumable chunks on the queue.
 */
#[Title('Overture imports')]
class OvertureImports extends Component
{
    use WithFileUploads;

    /** upload, remote or duckdb */
    public string $mode = 'upload';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $remoteUrl = '';

    public string $format = 'geojson';

    public string $label = '';

    public string $country = 'SA';

    public string $city = '';

    public string $region = '';

    public string $releaseDate = '';

    public string $sourceRelease = '';

    /** @var array{min_lon: string, min_lat: string, max_lon: string, max_lat: string} */
    public array $bbox = ['min_lon' => '', 'min_lat' => '', 'max_lon' => '', 'max_lat' => ''];

    public function mount(): void
    {
        $this->authorize('manage-data-sources');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $maxKb = (int) config('sources.overture.max_upload_mb') * 1024;
        $bboxRequired = $this->mode === 'duckdb' ? 'required' : 'nullable';

        return [
            'mode' => ['required', Rule::in(['upload', 'remote', 'duckdb'])],
            'file' => [Rule::requiredIf($this->mode === 'upload'), 'nullable', 'file', "max:{$maxKb}", 'extensions:geojson,json,ndjson,geojsonl,geojsons,jsonl,csv,txt'],
            'remoteUrl' => [Rule::requiredIf($this->mode === 'remote'), 'nullable', 'url:https', 'max:1000'],
            'format' => ['required', Rule::in(array_keys(OvertureImport::FORMATS))],
            'label' => ['nullable', 'string', 'max:150'],
            'country' => ['nullable', Rule::enum(Country::class)],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'releaseDate' => ['nullable', 'date'],
            'sourceRelease' => ['nullable', 'string', 'max:50', 'regex:/^[0-9A-Za-z.\-_]+$/'],
            'bbox.min_lon' => [$bboxRequired, 'required_with:bbox.max_lon', 'nullable', 'numeric', 'between:-180,180'],
            'bbox.max_lon' => [$bboxRequired, 'required_with:bbox.min_lon', 'nullable', 'numeric', 'between:-180,180', 'gt:bbox.min_lon'],
            'bbox.min_lat' => [$bboxRequired, 'required_with:bbox.max_lat', 'nullable', 'numeric', 'between:-90,90'],
            'bbox.max_lat' => [$bboxRequired, 'required_with:bbox.min_lat', 'nullable', 'numeric', 'between:-90,90', 'gt:bbox.min_lat'],
        ];
    }

    public function save(OvertureDuckDb $duckDb): void
    {
        $this->authorize('manage-data-sources');
        $this->validate();

        if ($this->mode === 'duckdb' && ! $duckDb->isAvailable()) {
            $this->addError('mode', (string) $duckDb->unavailableReason());

            return;
        }

        $bbox = $this->bbox['min_lon'] !== '' ? array_map('floatval', $this->bbox) : null;
        $path = null;
        $size = 0;

        if ($this->mode === 'upload') {
            $extension = $this->format === 'csv' ? 'csv' : ($this->format === 'geojson' ? 'geojson' : 'ndjson');
            $path = $this->file->storeAs(trim((string) config('sources.overture.directory'), '/'), 'upload-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).".{$extension}", config('sources.overture.disk'));
            $size = (int) $this->file->getSize();
        }

        $import = OvertureImport::query()->create([
            'label' => trim($this->label) !== '' ? trim($this->label) : (trim(implode(' ', array_filter([$this->city, $this->country]))) ?: 'Overture extract'),
            'mode' => $this->mode,
            'format' => $this->mode === 'duckdb' ? 'ndjson' : $this->format,
            'country_code' => $this->country !== '' ? $this->country : null,
            'city' => $this->city ?: null,
            'region' => $this->region ?: null,
            'bbox' => $bbox,
            'release_date' => $this->releaseDate ?: null,
            'source_release' => $this->sourceRelease ?: null,
            'file_path' => $path,
            'original_name' => $this->file?->getClientOriginalName(),
            'remote_url' => $this->mode === 'remote' ? $this->remoteUrl : null,
            'file_size' => $size,
        ]);

        ImportOvertureExtract::dispatch($import);

        session()->flash('status', 'Import queued. It runs in chunks in the background.');
        $this->redirectRoute('settings.overture');
    }

    public function pause(int $id): void
    {
        $this->authorize('manage-data-sources');

        OvertureImport::query()->whereKey($id)->whereIn('status', [ImportStatus::Pending, ImportStatus::Running])->update(['status' => ImportStatus::Paused]);
    }

    /**
     * Resume a paused import, or retry a failed one from where it stopped.
     */
    public function resume(int $id): void
    {
        $this->authorize('manage-data-sources');

        $import = OvertureImport::query()->whereKey($id)->whereIn('status', [ImportStatus::Paused, ImportStatus::Failed])->first();

        if ($import !== null) {
            $import->forceFill(['status' => ImportStatus::Pending, 'error_message' => null])->save();
            ImportOvertureExtract::dispatch($import);
        }
    }

    public function remove(int $id, OvertureExtractImporter $importer): void
    {
        $this->authorize('manage-data-sources');

        $import = OvertureImport::query()->find($id);

        if ($import !== null && ! $import->status->isActive()) {
            $importer->remove($import);
            session()->flash('status', 'Import and its places removed.');
            $this->redirectRoute('settings.overture');
        }
    }

    public function render(OvertureDuckDb $duckDb): View
    {
        $imports = OvertureImport::query()->latest()->latest('id')->get();

        return view('livewire.settings.overture-imports', [
            'imports' => $imports,
            'hasActive' => $imports->contains(fn (OvertureImport $import): bool => $import->status->isActive()),
            'formats' => OvertureImport::FORMATS,
            'countries' => Country::options(),
            'maxUploadMb' => (int) config('sources.overture.max_upload_mb'),
            'duckDbAvailable' => (bool) config('sources.overture.duckdb.enabled') && $duckDb->isAvailable(),
        ]);
    }
}

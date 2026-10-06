@php
    $input = 'mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none';
    $label = 'block text-sm font-medium text-slate-700';
    $error = 'mt-1 text-sm text-rose-600';
@endphp

<div class="space-y-6">
    @if ($hasActive)
        <div wire:poll.5s></div>
    @endif

    <div>
        <a href="{{ route('settings.sources') }}" class="text-sm text-slate-500 hover:text-slate-700">← Data sources</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">Overture imports</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Overture is searched locally from bounded extracts — never the global dataset. Export a small area (one city) as GeoJSON,
            GeoJSONSeq/NDJSON or CSV, e.g. with <code class="rounded bg-slate-100 px-1">overturemaps download --bbox=… -f geojson --type=place</code>.
            Overture's confidence is kept as an existence signal only. Larger files: upload them with the cPanel File Manager and run
            <code class="rounded bg-slate-100 px-1">php artisan overture:import</code>.
        </p>
    </div>

    <form wire:submit="save" class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-base font-semibold">New extract</h2>

        <div class="flex flex-wrap gap-4 text-sm">
            <label class="inline-flex items-center gap-2"><input type="radio" wire:model.live="mode" value="upload"> Upload a file (max {{ $maxUploadMb }} MB)</label>
            <label class="inline-flex items-center gap-2"><input type="radio" wire:model.live="mode" value="remote"> Small remote extract URL</label>
            <label @class(['inline-flex items-center gap-2', 'text-slate-400' => ! $duckDbAvailable])>
                <input type="radio" wire:model.live="mode" value="duckdb" @disabled(! $duckDbAvailable)> DuckDB extract {{ $duckDbAvailable ? '' : '(not available on this server)' }}
            </label>
        </div>
        @error('mode') <p class="{{ $error }}">{{ $message }}</p> @enderror

        <div class="grid gap-5 sm:grid-cols-2">
            @if ($mode === 'upload')
                <div>
                    <label for="file" class="{{ $label }}">Extract file</label>
                    <input id="file" type="file" wire:model="file" class="mt-1 block w-full text-sm">
                    <div wire:loading wire:target="file" class="mt-1 text-xs text-slate-500">Uploading…</div>
                    @error('file') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            @elseif ($mode === 'remote')
                <div>
                    <label for="remoteUrl" class="{{ $label }}">Extract URL (https)</label>
                    <input id="remoteUrl" type="url" wire:model="remoteUrl" class="{{ $input }}" placeholder="https://example.com/riyadh-places.geojson">
                    @error('remoteUrl') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($mode !== 'duckdb')
                <div>
                    <label for="format" class="{{ $label }}">Format</label>
                    <select id="format" wire:model="format" class="{{ $input }}">
                        @foreach ($formats as $value => $formatLabel)
                            <option value="{{ $value }}">{{ $formatLabel }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="label" class="{{ $label }}">Label</label>
                <input id="label" type="text" wire:model="label" class="{{ $input }}" placeholder="Riyadh – September release">
            </div>
            <div>
                <label for="country" class="{{ $label }}">Country</label>
                <select id="country" wire:model="country" class="{{ $input }}">
                    @foreach ($countries as $code => $countryLabel)
                        <option value="{{ $code }}">{{ $countryLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="city" class="{{ $label }}">City / area</label>
                <input id="city" type="text" wire:model="city" class="{{ $input }}">
            </div>
            <div>
                <label for="region" class="{{ $label }}">Region</label>
                <input id="region" type="text" wire:model="region" class="{{ $input }}">
            </div>
            <div>
                <label for="releaseDate" class="{{ $label }}">Release date</label>
                <input id="releaseDate" type="date" wire:model="releaseDate" class="{{ $input }}">
                @error('releaseDate') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="sourceRelease" class="{{ $label }}">Source release</label>
                <input id="sourceRelease" type="text" wire:model="sourceRelease" class="{{ $input }}" placeholder="2025-09-24.0">
                @error('sourceRelease') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset>
            <legend class="{{ $label }}">Bounding box {{ $mode === 'duckdb' ? '(required)' : '(optional: places outside are skipped)' }}</legend>
            <div class="mt-1 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (['min_lon' => 'Min longitude', 'min_lat' => 'Min latitude', 'max_lon' => 'Max longitude', 'max_lat' => 'Max latitude'] as $key => $bboxLabel)
                    <div>
                        <label for="bbox-{{ $key }}" class="text-xs text-slate-500">{{ $bboxLabel }}</label>
                        <input id="bbox-{{ $key }}" type="text" inputmode="decimal" wire:model="bbox.{{ $key }}" class="{{ $input }}">
                        @error("bbox.{$key}") <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60">Queue import</button>
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Extract</th>
                    <th class="px-4 py-3">Area</th>
                    <th class="px-4 py-3">Release</th>
                    <th class="px-4 py-3 text-right">Size</th>
                    <th class="px-4 py-3 text-right">Rows</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($imports as $import)
                    <tr wire:key="import-{{ $import->id }}">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $import->label }}</div>
                            <div class="text-xs text-slate-500">{{ $import->mode }} · {{ $import->format }} · {{ $import->created_at->diffForHumans() }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ collect([$import->city, $import->region, $import->country_code])->filter()->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $import->source_release ?? '—' }} {{ $import->release_date?->toDateString() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\BoundedDownloader::megabytes($import->file_size) }}</td>
                        <td class="px-4 py-3 text-right text-xs tabular-nums">
                            {{ number_format($import->rows_imported) }} imported
                            <div class="text-slate-500">{{ number_format($import->rows_processed) }} read · {{ number_format($import->rows_skipped) }} skipped · {{ number_format($import->rows_invalid) }} invalid</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ $import->status->label() }}</span>
                            @if ($import->status->isActive() && $import->progressPercent() !== null)
                                <span class="text-xs text-slate-500">{{ $import->progressPercent() }}%</span>
                            @endif
                            @if ($import->error_message)
                                <div class="max-w-xs text-xs break-words text-rose-700">{{ $import->error_message }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($import->status->isActive())
                                <button type="button" wire:click="pause({{ $import->id }})" class="text-xs text-slate-600 hover:underline">Pause</button>
                            @else
                                @if (in_array($import->status, [\App\Enums\ImportStatus::Paused, \App\Enums\ImportStatus::Failed], true))
                                    <button type="button" wire:click="resume({{ $import->id }})" class="text-xs text-indigo-600 hover:underline">{{ $import->status === \App\Enums\ImportStatus::Failed ? 'Retry' : 'Resume' }}</button>
                                @endif
                                <button type="button" wire:click="remove({{ $import->id }})" wire:confirm="Remove this extract and every place it imported?" class="ml-2 text-xs text-rose-600 hover:underline">Remove</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No extracts imported yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

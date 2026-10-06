@php
    $input = 'mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none';
    $label = 'block text-sm font-medium text-slate-700';
    $error = 'mt-1 text-sm text-rose-600';
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('settings.sources') }}" class="text-sm text-slate-500 hover:text-slate-700">← Data sources</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">AllThePlaces brand spiders</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Each spider is one brand's official store locator. Syncing downloads only that spider's GeoJSON from the latest run
            (never the global archive), keeps branches in your countries, records the brand's branch count and links branches to
            businesses as first-party chain evidence. Spider names are listed on
            <a href="https://www.alltheplaces.xyz/spiders" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">alltheplaces.xyz</a>.
        </p>
    </div>

    <form wire:submit="add" class="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="spider" class="{{ $label }}">Spider</label>
            <input id="spider" type="text" wire:model="spider" class="{{ $input }}" placeholder="kfc">
            @error('spider') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="brandName" class="{{ $label }}">Brand</label>
            <input id="brandName" type="text" wire:model="brandName" class="{{ $input }}" placeholder="KFC">
            @error('brandName') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="brandWikidata" class="{{ $label }}">Brand Wikidata ID <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="brandWikidata" type="text" wire:model="brandWikidata" class="{{ $input }}" placeholder="Q524757">
            @error('brandWikidata') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="country" class="{{ $label }}">Country <span class="font-normal text-slate-400">(optional)</span></label>
            <select id="country" wire:model="country" class="{{ $input }}">
                <option value="">All supported countries</option>
                @foreach ($countries as $code => $countryLabel)
                    <option value="{{ $code }}">{{ $countryLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="notes" class="{{ $label }}">Notes</label>
            <input id="notes" type="text" wire:model="notes" class="{{ $input }}">
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Add spider</button>
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Brand</th>
                    <th class="px-4 py-3">Spider</th>
                    <th class="px-4 py-3">Country</th>
                    <th class="px-4 py-3">Enabled</th>
                    <th class="px-4 py-3">Last successful sync</th>
                    <th class="px-4 py-3 text-right">Features</th>
                    <th class="px-4 py-3 text-right">Stored / matched</th>
                    <th class="px-4 py-3">Last error</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($spiders as $spider)
                    <tr wire:key="spider-{{ $spider->id }}">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $spider->brand_name }}</div>
                            @if ($spider->brand_wikidata)
                                <div class="text-xs text-slate-500">{{ $spider->brand_wikidata }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $spider->spider }}</code></td>
                        <td class="px-4 py-3 text-slate-600">{{ $spider->country_code ?? 'All' }}</td>
                        <td class="px-4 py-3">
                            <button type="button" wire:click="toggle({{ $spider->id }})" class="text-xs {{ $spider->enabled ? 'text-emerald-700' : 'text-slate-400' }} hover:underline">{{ $spider->enabled ? 'Enabled' : 'Disabled' }}</button>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $spider->last_success_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($spider->feature_count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($spider->stored_count) }} / {{ number_format($spider->matched_count) }}</td>
                        <td class="max-w-xs px-4 py-3 text-xs break-words text-rose-700">{{ $spider->last_error }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($spider->enabled)
                                <button type="button" wire:click="sync({{ $spider->id }})" class="text-xs text-indigo-600 hover:underline">Run sync</button>
                            @endif
                            @if ($spider->last_result)
                                <button type="button" wire:click="$set('showResult', {{ $showResult === $spider->id ? 'null' : $spider->id }})" class="ml-2 text-xs text-slate-600 hover:underline">Last result</button>
                            @endif
                            <button type="button" wire:click="remove({{ $spider->id }})" wire:confirm="Remove this spider and its stored branches?" class="ml-2 text-xs text-rose-600 hover:underline">Remove</button>
                        </td>
                    </tr>
                    @if ($showResult === $spider->id && $spider->last_result)
                        <tr wire:key="spider-result-{{ $spider->id }}">
                            <td colspan="9" class="bg-slate-50 px-4 py-3">
                                <pre class="overflow-auto text-xs" dir="ltr">{{ json_encode($spider->last_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">No spiders yet. Add the chains common in your target cities (e.g. kfc, mcdonalds, starbucks).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

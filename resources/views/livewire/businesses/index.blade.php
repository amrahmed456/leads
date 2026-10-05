@php
    $control = 'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none';
    $label = 'mb-1 block text-xs font-medium text-slate-500';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Businesses</h1>
        <p class="text-sm text-slate-500">{{ number_format($businesses->total()) }} {{ Str::plural('result', $businesses->total()) }}</p>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="{{ $label }}">Search</label>
                <input id="search" type="search" wire:model.live.debounce.400ms="search" class="{{ $control }}" placeholder="Name, phone, website, e-mail or address">
            </div>

            <div>
                <label for="campaign" class="{{ $label }}">Campaign</label>
                <select id="campaign" wire:model.live="campaign" class="{{ $control }}">
                    <option value="">All campaigns</option>
                    @foreach ($campaigns as $id => $campaignName)
                        <option value="{{ $id }}">{{ $campaignName }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="category" class="{{ $label }}">Category</label>
                <select id="category" wire:model.live="category" class="{{ $control }}">
                    <option value="">All categories</option>
                    @foreach ($categories as $value => $categoryLabel)
                        <option value="{{ $value }}">{{ $categoryLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="city" class="{{ $label }}">City</label>
                <select id="city" wire:model.live="city" class="{{ $control }}">
                    <option value="">All cities</option>
                    @foreach ($cities as $cityOption)
                        <option value="{{ $cityOption }}">{{ $cityOption }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="provider" class="{{ $label }}">Provider</label>
                <select id="provider" wire:model.live="provider" class="{{ $control }}">
                    <option value="">All providers</option>
                    @foreach ($providers as $key => $providerLabel)
                        <option value="{{ $key }}">{{ $providerLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="website" class="{{ $label }}">Website</label>
                    <select id="website" wire:model.live="website" class="{{ $control }}">
                        <option value="">Any</option>
                        <option value="with">Has website</option>
                        <option value="without">No website</option>
                    </select>
                </div>
                <div>
                    <label for="phone" class="{{ $label }}">Phone</label>
                    <select id="phone" wire:model.live="phone" class="{{ $control }}">
                        <option value="">Any</option>
                        <option value="with">Has phone</option>
                        <option value="without">No phone</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="sort" class="{{ $label }}">Sort by</label>
                <select id="sort" wire:model.live="sort" class="{{ $control }}">
                    @foreach ($sorts as $value => $sortLabel)
                        <option value="{{ $value }}">{{ $sortLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($filtered)
            <div class="mt-3 text-right">
                <button type="button" wire:click="clearFilters" class="text-sm text-slate-500 hover:text-slate-800">Clear filters</button>
            </div>
        @endif
    </div>

    <div wire:loading.class="opacity-60" wire:target="search, campaign, category, city, provider, website, phone, sort, clearFilters, gotoPage, nextPage, previousPage">
        <x-business-table :businesses="$businesses" :empty="$filtered ? 'No businesses match these filters.' : 'No businesses discovered yet. Start a search campaign to find some.'" />
    </div>

    {{ $businesses->links() }}
</div>

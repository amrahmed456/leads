@php
    $input = 'mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none';
    $label = 'block text-sm font-medium text-slate-700';
    $help = 'mt-1 text-xs text-slate-500';
    $error = 'mt-1 text-sm text-rose-600';
@endphp

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <a href="{{ route('campaigns.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Campaigns</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">New search campaign</h1>
        <p class="mt-1 text-sm text-slate-600">
            Discovery searches a circle around the city centre (or your own coordinates) for businesses of the chosen category.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <section class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">What to look for</h2>

            <div>
                <label for="category" class="{{ $label }}">Business category</label>
                <select id="category" wire:model="category" class="{{ $input }}" required>
                    <option value="">Choose a category…</option>
                    @foreach ($categories as $value => $categoryLabel)
                        <option value="{{ $value }}">{{ $categoryLabel }}</option>
                    @endforeach
                </select>
                @error('category') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="keywords" class="{{ $label }}">Keywords <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="keywords" type="text" wire:model="keywords" class="{{ $input }}" placeholder="مطعم, restaurant, cafe">
                <p class="{{ $help }}">
                    Comma-separated, any language. Also includes businesses of any type whose name contains one of these words.
                </p>
                @error('keywords') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="name" class="{{ $label }}">Campaign name <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="name" type="text" wire:model="name" maxlength="150" class="{{ $input }}" placeholder="e.g. Riyadh Restaurants">
                <p class="{{ $help }}">Defaults to “Category in City”.</p>
                @error('name') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Where</h2>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="country" class="{{ $label }}">Country</label>
                    <select id="country" wire:model.live="country" class="{{ $input }}" required>
                        @foreach ($countries as $code => $countryLabel)
                            <option value="{{ $code }}">{{ $countryLabel }}</option>
                        @endforeach
                    </select>
                    @error('country') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="city" class="{{ $label }}">City or area</label>
                    <input id="city" type="text" wire:model="city" list="city-suggestions" maxlength="120" class="{{ $input }}" placeholder="e.g. Riyadh or Al Olaya, Riyadh" required>
                    <datalist id="city-suggestions">
                        @foreach ($citySuggestions as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                    @error('city') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="sm:w-1/2 sm:pr-2.5">
                <label for="radius" class="{{ $label }}">Search radius (km)</label>
                <input id="radius" type="number" wire:model="radiusKm" step="0.5" min="{{ $radius['min_km'] }}" max="{{ $radius['max_km'] }}" class="{{ $input }}" required>
                <p class="{{ $help }}">{{ $radius['min_km'] }}–{{ $radius['max_km'] }} km around the centre point. Large radii in dense cities may hit the result limit.</p>
                @error('radiusKm') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-md border border-slate-200 bg-slate-50/60 px-4 py-4">
                <p class="text-sm font-medium text-slate-700">Custom centre point <span class="font-normal text-slate-400">(optional)</span></p>
                <p class="{{ $help }}">
                    Leave empty to locate the city automatically (OpenStreetMap Nominatim). Set coordinates to search around a specific district or street.
                </p>
                <div class="mt-3 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="latitude" class="{{ $label }}">Latitude</label>
                        <input id="latitude" type="text" inputmode="decimal" wire:model="latitude" class="{{ $input }}" placeholder="24.7136">
                        @error('latitude') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="longitude" class="{{ $label }}">Longitude</label>
                        <input id="longitude" type="text" inputmode="decimal" wire:model="longitude" class="{{ $input }}" placeholder="46.6753">
                        @error('longitude') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Data source</h2>

            <div>
                <label for="provider" class="{{ $label }}">Discovery provider</label>
                <select id="provider" wire:model="provider" class="{{ $input }}" required>
                    @foreach ($providers as $key => $providerLabel)
                        <option value="{{ $key }}">{{ $providerLabel }}</option>
                    @endforeach
                </select>
                <p class="{{ $help }}">
                    OpenStreetMap is free and community-maintained. Coverage varies by area, and it has no ratings or reviews.
                </p>
                @error('provider') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('campaigns.index') }}" class="rounded-md px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">Cancel</a>
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Create &amp; start discovery</span>
                <span wire:loading wire:target="save">Creating…</span>
            </button>
        </div>
    </form>
</div>

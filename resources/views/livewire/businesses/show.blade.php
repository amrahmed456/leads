<div class="space-y-6">
    <div>
        <a href="{{ route('businesses.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Businesses</a>

        <div class="mt-2">
            <h1 class="text-2xl font-semibold tracking-tight" dir="auto">{{ $business->name }}</h1>
            @if ($business->alternate_name)
                <p class="text-slate-500" dir="auto">{{ $business->alternate_name }}</p>
            @endif
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700 ring-1 ring-indigo-600/20 ring-inset">{{ $business->category?->label() ?? 'Uncategorized' }}</span>
                @if ($business->type)
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{{ $business->typeLabel() }}</span>
                @endif
                @if (! $business->website)
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset">No website listed</span>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Contact</h2>
                <dl class="divide-y divide-slate-100">
                    <x-field label="Phone">
                        @if ($business->phone)
                            <a href="tel:{{ $business->phone }}" class="tabular-nums text-indigo-600 hover:underline" dir="ltr">{{ $business->phone }}</a>
                        @endif
                    </x-field>
                    <x-field label="Website">
                        @if ($business->website)
                            <a href="{{ $business->website }}" target="_blank" rel="noopener noreferrer nofollow" class="text-indigo-600 hover:underline">{{ $business->website }}</a>
                        @endif
                    </x-field>
                    <x-field label="E-mail">
                        @if ($business->email)
                            <a href="mailto:{{ $business->email }}" class="text-indigo-600 hover:underline">{{ $business->email }}</a>
                        @endif
                    </x-field>
                    <x-field label="Social profiles">
                        @foreach ($business->social_links ?? [] as $network => $url)
                            <div>
                                <span class="text-slate-500">{{ Str::headline($network) }}:</span>
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow" class="text-indigo-600 hover:underline">{{ $url }}</a>
                            </div>
                        @endforeach
                    </x-field>
                    <x-field label="Opening hours">{{ $business->opening_hours }}</x-field>
                </dl>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Location</h2>
                <dl class="divide-y divide-slate-100">
                    <x-field label="Address"><span dir="auto">{{ $business->address }}</span></x-field>
                    <x-field label="City">{{ $business->city }}</x-field>
                    <x-field label="Country">{{ \App\Enums\Country::tryFrom((string) $business->country_code)?->label() ?? $business->country_code }}</x-field>
                    <x-field label="Coordinates">
                        @if ($business->latitude !== null && $business->longitude !== null)
                            <a href="https://www.openstreetmap.org/?mlat={{ $business->latitude }}&mlon={{ $business->longitude }}#map=18/{{ $business->latitude }}/{{ $business->longitude }}"
                               target="_blank" rel="noopener noreferrer" class="tabular-nums text-indigo-600 hover:underline">{{ $business->latitude }}, {{ $business->longitude }}</a>
                        @endif
                    </x-field>
                </dl>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Sources</h2>
                <ul class="divide-y divide-slate-100">
                    @foreach ($business->sources as $source)
                        <li wire:key="source-{{ $source->id }}" class="px-5 py-4 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $source->providerLabel() }}</span>
                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $source->provider_id }}</code>
                                    @if ($source->is_primary)
                                        <span class="text-xs text-slate-500">primary</span>
                                    @endif
                                </div>
                                @if ($source->source_url)
                                    <a href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">View on {{ $source->providerLabel() }} ↗</a>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                First seen {{ $source->first_seen_at->toDayDateTimeString() }} UTC · last seen {{ $source->last_seen_at->diffForHumans() }}
                            </p>
                            @if ($source->raw_data)
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-700">Raw provider data</summary>
                                    <pre class="mt-2 max-h-80 overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100" dir="ltr">{{ json_encode($source->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($business->sources->contains('provider', 'openstreetmap'))
                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">
                        OpenStreetMap data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer" class="underline">OpenStreetMap contributors</a>, ODbL.
                    </p>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Reputation</h2>
                <dl class="divide-y divide-slate-100">
                    <x-field label="Rating">{{ $business->rating !== null ? number_format($business->rating, 1).' ★' : '' }}</x-field>
                    <x-field label="Reviews">{{ $business->reviews_count !== null ? number_format($business->reviews_count) : '' }}</x-field>
                </dl>
                @if ($business->rating === null && $business->reviews_count === null)
                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">Not available from the current data source.</p>
                @endif
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Discovery</h2>
                <dl class="divide-y divide-slate-100">
                    <x-field label="First discovered">{{ $business->first_discovered_at->toDayDateTimeString() }} UTC</x-field>
                    <x-field label="Last seen">{{ $business->last_seen_at->toDayDateTimeString() }} UTC</x-field>
                </dl>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Found by campaigns</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($business->campaigns as $campaign)
                        <li wire:key="campaign-{{ $campaign->id }}" class="px-5 py-3">
                            <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium hover:text-indigo-600">{{ $campaign->name }}</a>
                            <div class="text-xs text-slate-500">{{ $campaign->pivot->created_at?->diffForHumans() }}</div>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-slate-500">—</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

@props(['businesses', 'empty' => 'No businesses yet.'])

<div {{ $attributes->class('overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm') }}>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
            <tr>
                <th class="px-4 py-3">Lead</th>
                <th class="px-4 py-3">Business</th>
                <th class="px-4 py-3">Entity</th>
                <th class="px-4 py-3">Top opportunity</th>
                <th class="px-4 py-3">Category</th>
                <th class="px-4 py-3">City</th>
                <th class="px-4 py-3">Phone</th>
                <th class="px-4 py-3">Website</th>
                <th class="px-4 py-3">Rating</th>
                <th class="px-4 py-3">Source</th>
                <th class="px-4 py-3">Discovered</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($businesses as $business)
                @php($topOpportunity = $business->opportunities->sortByDesc(fn ($opportunity) => $opportunity->priority->rank())->first())
                <tr wire:key="business-{{ $business->id }}" class="hover:bg-slate-50">
                    <td class="px-4 py-3"><x-lead-score :score="$business->leadScore" /></td>
                    <td class="max-w-xs px-4 py-3">
                        <a href="{{ route('businesses.show', $business) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $business->name }}</a>
                        @if ($business->alternate_name)
                            <div class="truncate text-xs text-slate-500">{{ $business->alternate_name }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <x-entity-type :type="$business->entity_type" :confidence="$business->entity_confidence" />
                        @if ($business->brand)
                            <div class="mt-0.5 text-xs text-slate-500" title="Brand ({{ $business->brand_confidence }}% confidence)">{{ $business->brand->name }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if ($topOpportunity)
                            <span class="inline-flex items-center gap-1 text-slate-700" title="{{ $topOpportunity->priority->label() }} priority">
                                <span aria-hidden="true">{{ match ($topOpportunity->priority) { \App\Enums\OpportunityPriority::High => '🔥', \App\Enums\OpportunityPriority::Medium => '🟠', default => '🟡' } }}</span>
                                {{ $topOpportunity->type->label() }}
                            </span>
                            @if ($business->opportunities->count() > 1)
                                <span class="text-xs text-slate-400">+{{ $business->opportunities->count() - 1 }}</span>
                            @endif
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                        {{ $business->category?->label() ?? 'Uncategorized' }}
                        @if ($business->type)
                            <div class="text-xs text-slate-400">{{ $business->typeLabel() }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $business->city ?? '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap tabular-nums text-slate-600" dir="ltr">
                        @if ($business->phone)
                            <a href="tel:{{ $business->phone }}" class="hover:text-indigo-600">{{ $business->phone }}</a>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="max-w-[14rem] px-4 py-3">
                        @if ($business->website)
                            <a href="{{ $business->website }}" target="_blank" rel="noopener noreferrer nofollow" class="block truncate text-indigo-600 hover:underline">{{ $business->websiteHost() }}</a>
                        @elseif ($business->social_links)
                            <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset" title="{{ implode(', ', array_keys($business->social_links)) }}">Social only</span>
                        @else
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">No website</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                        @if ($business->rating !== null)
                            {{ number_format($business->rating, 1) }} ★
                            @if ($business->reviews_count !== null)
                                <span class="text-xs text-slate-400">({{ number_format($business->reviews_count) }})</span>
                            @endif
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @foreach ($business->sources->pluck('provider')->unique() as $provider)
                            <span class="inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ app(\App\Sources\SourceRegistry::class)->label($provider) }}</span>
                        @endforeach
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-500" title="{{ $business->first_discovered_at->toDayDateTimeString() }} UTC">
                        {{ $business->first_discovered_at->diffForHumans() }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="px-4 py-10 text-center text-slate-500">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

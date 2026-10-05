<div class="space-y-6">
    @if ($hasActive)
        <div wire:poll.5s></div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Search campaigns</h1>

        <div class="flex items-center gap-3">
            <label for="status-filter" class="sr-only">Status</label>
            <select id="status-filter" wire:model.live="status" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                @endforeach
            </select>

            <a href="{{ route('campaigns.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">New campaign</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3">Campaign</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3">Radius</th>
                    <th class="px-4 py-3">Provider</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Businesses</th>
                    <th class="px-4 py-3 text-right">New</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($campaigns as $campaign)
                    <tr wire:key="campaign-{{ $campaign->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium hover:text-indigo-600">{{ $campaign->name }}</a>
                            <div class="text-xs text-slate-500">
                                {{ $campaign->category->label() }}
                                @if ($campaign->keywords)
                                    · {{ implode(', ', $campaign->keywords) }}
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $campaign->city }}, {{ $campaign->country_code->label() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $campaign->radiusKm() }} km</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $campaign->providerLabel() }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$campaign->status" /></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($campaign->results_count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ number_format($campaign->new_businesses_count) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $campaign->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                            @if ($status !== '')
                                No campaigns with this status.
                            @else
                                No campaigns yet. <a href="{{ route('campaigns.create') }}" class="text-indigo-600 hover:underline">Create your first campaign</a>.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $campaigns->links() }}
</div>

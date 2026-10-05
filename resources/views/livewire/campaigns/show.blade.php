<div class="space-y-6">
    @if ($campaign->status->isActive())
        <div wire:poll.3s></div>
    @elseif (($auditCounts['pending'] ?? 0) + ($auditCounts['running'] ?? 0) > 0)
        <div wire:poll.10s></div>
    @endif

    <div>
        <a href="{{ route('campaigns.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Campaigns</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $campaign->name }}</h1>
                    <x-status-badge :status="$campaign->status" />
                </div>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $campaign->category->label() }} · {{ $campaign->city }}, {{ $campaign->country_code->label() }}
                    · {{ $campaign->radiusKm() }} km radius · {{ $campaign->providerLabel() }}
                </p>
            </div>

            @can('run', $campaign)
                <button type="button" wire:click="startDiscovery" wire:loading.attr="disabled"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60">
                    {{ $campaign->status === \App\Enums\CampaignStatus::Failed ? 'Retry discovery' : 'Run discovery again' }}
                </button>
            @endcan
        </div>
    </div>

    @switch ($campaign->status)
        @case (\App\Enums\CampaignStatus::Pending)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Queued — waiting for a queue worker.</p>
                @if ($campaign->error_message)
                    <p class="mt-1">{{ $campaign->error_message }}</p>
                @else
                    <p class="mt-1">If discovery does not start within a minute, make sure a worker is running: <code class="rounded bg-amber-100 px-1">php artisan queue:work</code></p>
                @endif
            </div>
            @break

        @case (\App\Enums\CampaignStatus::Running)
            <div class="flex items-center gap-3 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle>
                    <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                </svg>
                Discovering businesses… This page updates automatically.
            </div>
            @break

        @case (\App\Enums\CampaignStatus::Failed)
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                <p class="font-medium">Discovery failed</p>
                <p class="mt-1">{{ $campaign->error_message }}</p>
            </div>
            @break

        @case (\App\Enums\CampaignStatus::Completed)
            @if ($campaign->results_truncated)
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    The provider returned its maximum number of results, so some businesses may be missing. Use a smaller radius or split the area into several campaigns.
                </div>
            @endif
            @break
    @endswitch

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Businesses found" :value="$campaign->results_count" hint="In the latest run" />
        <x-stat label="New businesses" :value="$campaign->new_businesses_count" hint="Not seen in earlier campaigns" />
        <x-stat label="Skipped records" :value="$campaign->skipped_count" hint="Unnamed, not a business, or invalid" />
        <x-stat label="Last completed" :value="$campaign->completed_at?->diffForHumans() ?? '—'"
                :hint="$campaign->completed_at && $campaign->started_at ? 'Took '.$campaign->started_at->diffForHumans($campaign->completed_at, true) : null" />
    </dl>

    @if ($auditCounts->isNotEmpty())
        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border border-slate-200 bg-white px-5 py-3 text-sm text-slate-600 shadow-sm">
            <span class="font-semibold text-slate-900">Website audits</span>
            <span><span class="font-medium tabular-nums text-slate-900">{{ $auditCounts['completed'] ?? 0 }}</span> analyzed</span>
            <span><span class="font-medium tabular-nums text-slate-900">{{ ($auditCounts['pending'] ?? 0) + ($auditCounts['running'] ?? 0) }}</span> queued</span>
            <span><span class="font-medium tabular-nums text-slate-900">{{ $auditCounts['failed'] ?? 0 }}</span> could not be analyzed</span>
            <span><span class="font-medium tabular-nums text-slate-900">{{ $auditCounts['not_applicable'] ?? 0 }}</span> without a website</span>
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Search settings</h2>
        <dl class="divide-y divide-slate-100">
            <x-field label="Provider">{{ $campaign->providerLabel() }}</x-field>
            <x-field label="Category">{{ $campaign->category->label() }}</x-field>
            <x-field label="Keywords">
                @foreach ($campaign->keywords ?? [] as $keyword)
                    <span class="mr-1 inline-flex rounded bg-slate-100 px-1.5 py-0.5 text-xs" dir="auto">{{ $keyword }}</span>
                @endforeach
            </x-field>
            <x-field label="Location">{{ $campaign->city }}, {{ $campaign->country_code->label() }}</x-field>
            <x-field label="Search centre">
                @if ($campaign->hasCoordinates())
                    <a href="https://www.openstreetmap.org/?mlat={{ $campaign->latitude }}&mlon={{ $campaign->longitude }}#map=13/{{ $campaign->latitude }}/{{ $campaign->longitude }}"
                       target="_blank" rel="noopener noreferrer" class="tabular-nums text-indigo-600 hover:underline">{{ $campaign->latitude }}, {{ $campaign->longitude }}</a>
                    <div class="text-xs text-slate-500">{{ $campaign->resolved_location ?? 'Coordinates entered manually' }}</div>
                @elseif ($campaign->status->isActive())
                    <span class="text-slate-500">Resolved from the city name when discovery starts.</span>
                @endif
            </x-field>
            <x-field label="Radius">{{ $campaign->radiusKm() }} km</x-field>
            <x-field label="Created">{{ $campaign->created_at->toDayDateTimeString() }} UTC</x-field>
            <x-field label="Last started">{{ $campaign->started_at?->toDayDateTimeString() }}{{ $campaign->started_at ? ' UTC' : '' }}</x-field>
        </dl>
    </div>

    <section>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold">Businesses <span class="font-normal text-slate-500">({{ number_format($businesses->total()) }})</span></h2>
            @if ($businesses->total() > 0)
                <a href="{{ route('businesses.index', ['campaign' => $campaign->id]) }}" class="text-sm text-indigo-600 hover:underline">Search &amp; filter these businesses</a>
            @endif
        </div>

        <x-business-table :businesses="$businesses" :empty="$campaign->status->isActive() ? 'Businesses appear here when discovery completes.' : 'No businesses found for this campaign.'" />

        <div class="mt-4">{{ $businesses->links() }}</div>
    </section>
</div>

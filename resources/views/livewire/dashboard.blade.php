<div class="space-y-8">
    @if ($activeCampaignCount > 0)
        <div wire:poll.10s></div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <a href="{{ route('campaigns.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">New campaign</a>
    </div>

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Businesses discovered" :value="$businessCount" :href="route('businesses.index')" />
        <x-stat label="Search campaigns" :value="$campaignCount" :href="route('campaigns.index')" />
        <x-stat label="Campaigns in progress" :value="$activeCampaignCount" hint="Queued or running" />
        <x-stat label="Businesses without a website" :value="$withoutWebsiteCount"
                :href="route('businesses.index', ['website' => 'without'])"
                :hint="$businessCount > 0 ? round($withoutWebsiteCount / $businessCount * 100).'% of all businesses' : null" />
    </dl>

    <section>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold">Recent campaigns</h2>
            <a href="{{ route('campaigns.index') }}" class="text-sm text-indigo-600 hover:underline">View all</a>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th class="px-4 py-3">Campaign</th>
                        <th class="px-4 py-3">Location</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Businesses</th>
                        <th class="px-4 py-3">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($recentCampaigns as $campaign)
                        <tr wire:key="campaign-{{ $campaign->id }}" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium hover:text-indigo-600">{{ $campaign->name }}</a>
                                <div class="text-xs text-slate-500">{{ $campaign->category->label() }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $campaign->city }}, {{ $campaign->country_code->label() }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$campaign->status" /></td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($campaign->results_count) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $campaign->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                                No campaigns yet. <a href="{{ route('campaigns.create') }}" class="text-indigo-600 hover:underline">Create your first campaign</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold">Recently discovered businesses</h2>
            <a href="{{ route('businesses.index') }}" class="text-sm text-indigo-600 hover:underline">View all</a>
        </div>

        <x-business-table :businesses="$recentBusinesses" empty="No businesses discovered yet." />
    </section>
</div>

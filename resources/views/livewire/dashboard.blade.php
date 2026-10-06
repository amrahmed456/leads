<div class="space-y-8">
    @if ($activeCampaignCount > 0 || $activeAuditCount > 0)
        <div wire:poll.10s></div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <a href="{{ route('campaigns.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">New campaign</a>
    </div>

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Businesses discovered" :value="$businessCount" :href="route('businesses.index', ['hide_disqualified' => 0, 'sort' => 'newest'])"
                :hint="number_format($withWebsiteCount).' with a website · '.number_format($withoutWebsiteCount).' without'" />
        <x-stat label="Hot leads" :value="$statusCounts['hot'] ?? 0"
                :href="route('businesses.index', ['status' => 'hot'])"
                :hint="collect([$activeCampaignCount ? $activeCampaignCount.' campaign(s) running' : null, $activeAuditCount ? $activeAuditCount.' website audit(s) queued' : null])->filter()->implode(' · ') ?: 'Meet every HOT requirement'" />
        <x-stat label="Warm leads" :value="$statusCounts['warm'] ?? 0" :href="route('businesses.index', ['status' => 'warm'])" />
        <x-stat label="Review required" :value="$statusCounts['review_required'] ?? 0" :href="route('businesses.index', ['status' => 'review_required'])" hint="Uncertain identity or chain hints" />
        <x-stat label="Low" :value="$statusCounts['low'] ?? 0" :href="route('businesses.index', ['status' => 'low'])" />
        <x-stat label="Disqualified" :value="$statusCounts['disqualified'] ?? 0" :href="route('businesses.index', ['status' => 'disqualified', 'hide_disqualified' => 0])" />
        <x-stat label="Chains & enterprises rejected" :value="$rejectedChainCount"
                :href="route('businesses.index', ['entity' => 'chain', 'status' => 'disqualified', 'hide_disqualified' => 0])"
                hint="False hot leads avoided: recognized brands, chains and corporates" />
        <x-stat label="Search campaigns" :value="$campaignCount" :href="route('campaigns.index')" />
    </dl>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Entity types</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach (\App\Enums\EntityType::cases() as $type)
                    @continue(($entityCounts[$type->value] ?? 0) === 0 && $type === \App\Enums\EntityType::Unknown)
                    <li class="flex items-center justify-between px-5 py-2">
                        <a href="{{ route('businesses.index', ['entity' => $type->value, 'hide_disqualified' => 0]) }}" class="hover:text-indigo-600"><x-entity-type :type="$type" /></a>
                        <span class="tabular-nums text-slate-700">{{ number_format($entityCounts[$type->value] ?? 0) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Source contribution</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($sourceContribution as $source)
                    <li class="flex items-center justify-between gap-3 px-5 py-2">
                        <span>{{ $source['label'] }} <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ $source['group'] }}</span></span>
                        <span class="tabular-nums text-slate-700">{{ number_format($source['businesses']) }} <span class="text-xs text-slate-400">businesses</span></span>
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">Sources in the same group share an underlying dataset and never count as independent confirmation.</p>
        </section>
    </div>

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
            <h2 class="text-base font-semibold">Top hot &amp; warm leads</h2>
            <a href="{{ route('businesses.index') }}" class="text-sm text-indigo-600 hover:underline">View all</a>
        </div>

        <x-business-table :businesses="$topLeads" empty="No businesses scored yet. Scores appear after a campaign completes." />
    </section>

    <section>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold">Recently discovered businesses</h2>
            <a href="{{ route('businesses.index') }}" class="text-sm text-indigo-600 hover:underline">View all</a>
        </div>

        <x-business-table :businesses="$recentBusinesses" empty="No businesses discovered yet." />
    </section>
</div>

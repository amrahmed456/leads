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
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Opportunities</h2>
                @forelse ($opportunities as $opportunity)
                    <div wire:key="opportunity-{{ $opportunity->id }}" class="border-b border-slate-100 px-5 py-3 last:border-b-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-opportunity-priority :priority="$opportunity->priority" />
                            <span class="font-medium">{{ $opportunity->type->label() }}</span>
                        </div>
                        <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-sm text-slate-600">
                            @foreach ($opportunity->reasons as $reason)
                                <li dir="auto">{{ $reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-500">
                        @if ($business->leadScore === null)
                            Not evaluated yet.
                        @elseif ($currentAudit?->status->isActive())
                            None detected yet. The website audit has not finished.
                        @else
                            No opportunities detected.
                        @endif
                    </p>
                @endforelse
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-semibold">Digital presence</h2>
                    <button type="button" wire:click="analyze" wire:loading.attr="disabled" @disabled($currentAudit?->status->isActive())
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ $business->website === null ? 'Re-evaluate' : ($audit !== null ? 'Re-audit website' : 'Audit website') }}
                    </button>
                </div>

                @if ($currentAudit?->status->isActive())
                    <div wire:poll.4s class="flex flex-wrap items-center gap-2 border-b border-sky-100 bg-sky-50 px-5 py-2 text-sm text-sky-800">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle>
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                        </svg>
                        Website audit {{ $currentAudit->status === \App\Enums\AuditStatus::Running ? 'running' : 'queued' }}…
                        @if ($currentAudit->error_message)
                            <span class="text-xs text-sky-700">{{ $currentAudit->error_message }}</span>
                        @endif
                    </div>
                @endif

                <dl class="divide-y divide-slate-100">
                    <x-field label="Website">
                        @if ($business->website)
                            <a href="{{ $business->website }}" target="_blank" rel="noopener noreferrer nofollow" class="text-indigo-600 hover:underline">{{ $business->website }}</a>
                            @if ($audit?->final_url && $audit->final_url !== $audit->normalized_url)
                                <div class="text-xs break-all text-slate-500">Redirects to {{ $audit->final_url }}</div>
                            @endif
                        @else
                            <span class="font-medium text-amber-700">Not found</span>
                        @endif
                    </x-field>

                    @if ($business->website && $audit === null && ! $currentAudit?->status->isActive())
                        <x-field label="Audit"><span class="text-slate-500">Not audited yet.</span></x-field>
                    @elseif ($audit?->status === \App\Enums\AuditStatus::Failed)
                        <x-field label="Audit">
                            <span class="font-medium text-rose-700">{{ $audit->failure_type?->label() }}</span>
                            <div class="text-xs break-all text-slate-500">{{ $audit->error_message }} · checked {{ $audit->audited_at?->diffForHumans() }}</div>
                        </x-field>
                        <x-signal-row label="Reachable" :value="$audit->reachable" :detail="$audit->http_status ? 'HTTP '.$audit->http_status : null" />
                    @elseif ($audit?->isAnalyzed())
                        @php($deliveryApps = $audit->signalData(\App\Enums\WebsiteSignal::DeliveryApps)['platforms'] ?? [])
                        <x-signal-row label="Reachable" :value="true" :detail="'HTTP '.$audit->http_status.($audit->response_time_ms ? ' · '.number_format($audit->response_time_ms).' ms' : '')" />
                        <x-signal-row label="HTTPS" :value="$audit->is_https && $audit->ssl_valid !== false"
                                      :detail="match (true) { ! $audit->is_https => 'Served over plain HTTP', $audit->ssl_valid === false => 'Invalid certificate', default => 'Valid certificate' }" />
                        <x-signal-row label="Mobile signals" :value="$audit->has_viewport"
                                      :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::ResponsiveDesign) ?? $audit->signalDetail(\App\Enums\WebsiteSignal::MobileViewport)" />
                        <x-signal-row label="Phone on website" :value="$audit->has_phone" :detail="implode(', ', $audit->signalData(\App\Enums\WebsiteSignal::Phone)['numbers'] ?? [])" />
                        <x-signal-row label="E-mail on website" :value="$audit->has_email" :detail="implode(', ', $audit->signalData(\App\Enums\WebsiteSignal::Email)['addresses'] ?? [])" />
                        <x-signal-row label="WhatsApp" :value="$audit->has_whatsapp"
                                      :detail="implode(', ', $audit->signalData(\App\Enums\WebsiteSignal::Whatsapp)['numbers'] ?? []) ?: $audit->signalDetail(\App\Enums\WebsiteSignal::Whatsapp)" />
                        <x-signal-row label="Contact form" :value="$audit->has_contact_form" :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::ContactForm)" />
                        <x-signal-row label="Booking" :value="$audit->has_booking" :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::Booking)" />
                        <x-signal-row label="Online ordering" :value="$audit->has_online_ordering" :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::OnlineOrdering)" />
                        @if ($deliveryApps !== [])
                            <x-signal-row label="Delivery apps" :value="true" :detail="implode(', ', $deliveryApps)" />
                        @endif
                        @if ($audit->signalConfidence(\App\Enums\WebsiteSignal::MobileApp)->atLeast(\App\Enums\Confidence::Medium))
                            <x-signal-row label="Own mobile app" :value="true" :detail="implode(', ', $audit->signalData(\App\Enums\WebsiteSignal::MobileApp)['stores'] ?? [])" />
                        @endif
                        <x-signal-row label="E-commerce" :value="$audit->has_ecommerce" :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::Ecommerce)" />
                        <x-signal-row label="Cart / checkout" :value="$audit->has_cart" :detail="$audit->signalDetail(\App\Enums\WebsiteSignal::Cart)" />
                        <x-field label="Languages">
                            {{ collect(['Arabic' => $audit->has_arabic, 'English' => $audit->has_english])->filter()->keys()->implode(' / ') ?: 'Not detected' }}
                            @if ($audit->languages['alternates'] ?? [])
                                <span class="text-xs text-slate-500">· links to {{ implode(', ', array_map('strtoupper', $audit->languages['alternates'])) }} version</span>
                            @endif
                        </x-field>
                        <x-field label="Technology">
                            @if ($audit->cms)
                                <span class="font-medium">{{ $audit->cms }}</span>
                            @endif
                            @php($otherTechnologies = collect($audit->technologies ?? [])->pluck('name')->reject(fn (string $name): bool => $name === $audit->cms))
                            @if ($otherTechnologies->isNotEmpty())
                                <span class="text-slate-500">{{ $audit->cms ? '· ' : '' }}{{ $otherTechnologies->implode(', ') }}</span>
                            @endif
                        </x-field>
                    @endif
                </dl>

                @if ($audit?->isAnalyzed() && $audit->score !== null)
                    @php($checks = collect($audit->score_breakdown['checks'] ?? []))
                    <div class="border-t border-slate-200 px-5 py-4">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-medium text-slate-500">Audit score</span>
                            <span class="text-2xl font-semibold tabular-nums">{{ $audit->score }}<span class="text-sm font-normal text-slate-400"> / 100</span></span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div @class(['h-full rounded-full', 'bg-emerald-500' => $audit->score >= 70, 'bg-amber-500' => $audit->score >= 40 && $audit->score < 70, 'bg-rose-500' => $audit->score < 40])
                                 style="width: {{ $audit->score }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Measured from technical and digital presence signals only; visual design is not assessed.</p>

                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <div>
                                <h3 class="text-xs font-semibold tracking-wide text-emerald-700 uppercase">Positive signals</h3>
                                <ul class="mt-1 space-y-0.5 text-sm text-slate-700">
                                    @forelse ($checks->where('passed', true) as $check)
                                        <li><span aria-hidden="true" class="text-emerald-600">✓</span> {{ $check['label'] }} <span class="text-xs text-slate-400">+{{ $check['points'] }}</span></li>
                                    @empty
                                        <li class="text-slate-400">None</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <h3 class="text-xs font-semibold tracking-wide text-rose-700 uppercase">Negative signals</h3>
                                <ul class="mt-1 space-y-0.5 text-sm text-slate-700">
                                    @forelse ($checks->where('passed', false) as $check)
                                        <li><span aria-hidden="true" class="text-rose-600">✗</span> {{ $check['label'] }} <span class="text-xs text-slate-400">0/{{ $check['max'] }}</span></li>
                                    @empty
                                        <li class="text-slate-400">None</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </div>

                    <details class="border-t border-slate-200 px-5 py-3">
                        <summary class="cursor-pointer text-sm text-slate-500 hover:text-slate-700">Detection evidence</summary>
                        <dl class="mt-2 space-y-2 text-sm">
                            @foreach (\App\Enums\WebsiteSignal::cases() as $signal)
                                @continue($audit->signalEvidence($signal) === [])
                                <div>
                                    <dt class="font-medium text-slate-700">{{ $signal->label() }} <span class="text-xs font-normal text-slate-400">({{ $audit->signalConfidence($signal)->value }} confidence)</span></dt>
                                    <dd>
                                        <ul class="list-disc pl-5 text-slate-600">
                                            @foreach ($audit->signalEvidence($signal) as $evidence)
                                                <li dir="auto" class="break-all">{{ $evidence }}</li>
                                            @endforeach
                                        </ul>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </details>

                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">
                        Homepage checked {{ $audit->audited_at?->diffForHumans() }}{{ $audit->reused_audit_id ? ' (result shared with another business on the same website)' : '' }}. Only the homepage is fetched.
                    </p>
                @endif
            </div>

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
                <h2 class="border-b border-slate-200 px-5 py-3 text-base font-semibold">Lead score</h2>
                @if ($business->leadScore)
                    @php($breakdownTotal = array_sum(array_column($business->leadScore->breakdown, 'points')))
                    <div class="flex items-center gap-3 px-5 pt-4">
                        <span class="text-4xl font-semibold tabular-nums">{{ $business->leadScore->score }}</span>
                        <span class="text-sm text-slate-400">/ 100</span>
                        <span @class([
                            'ml-auto rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-rose-600 text-white' => $business->leadScore->grade === \App\Enums\LeadGrade::Hot,
                            'bg-amber-100 text-amber-800' => $business->leadScore->grade === \App\Enums\LeadGrade::Warm,
                            'bg-slate-100 text-slate-600' => $business->leadScore->grade === \App\Enums\LeadGrade::Cold,
                        ])>{{ $business->leadScore->grade->label() }} lead</span>
                    </div>
                    <ul class="divide-y divide-slate-100 px-5 py-3 text-sm">
                        @foreach ($business->leadScore->breakdown as $item)
                            <li class="flex justify-between gap-3 py-1.5">
                                <span class="text-slate-600" dir="auto">{{ $item['label'] }}</span>
                                <span @class([
                                    'font-medium tabular-nums',
                                    'text-emerald-700' => $item['points'] > 0,
                                    'text-rose-700' => $item['points'] < 0,
                                    'text-slate-400' => $item['points'] === 0,
                                ])>{{ $item['points'] > 0 ? '+' : '' }}{{ $item['points'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">
                        Rule-based score (rules v{{ $business->leadScore->version }}){{ $breakdownTotal > 100 ? ', capped at 100' : '' }} · updated {{ $business->leadScore->scored_at->diffForHumans() }}
                    </p>
                @else
                    <p class="px-5 py-4 text-sm text-slate-500">Not scored yet.</p>
                @endif
            </div>

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

@php
    $input = 'block w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none';
@endphp

<div class="space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Data sources</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                Discovery finds places; qualification decides whether they are worth contacting. Sources in the same independence group
                share an underlying dataset and never count as independent confirmation. Credentials are stored encrypted and never shown.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('settings.overture') }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Overture imports</a>
            <a href="{{ route('settings.alltheplaces') }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">AllThePlaces spiders</a>
        </div>
    </div>

    @foreach (['Free sources' => $free, 'Future / premium sources' => $future] as $heading => $rows)
        <section class="space-y-3">
            <h2 class="text-base font-semibold">{{ $heading }}</h2>

            @foreach ($rows as $row)
                @php($source = $row['source'])
                <div wire:key="source-{{ $row['key'] }}" @class(['rounded-lg border bg-white shadow-sm', 'border-slate-200' => $source->implemented, 'border-dashed border-slate-300 opacity-75' => ! $source->implemented])>
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{{ $source->label }}</h3>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 uppercase">{{ $source->tier }}</span>
                                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-700" title="Independence group">{{ $source->independenceGroup }}</span>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $row['health']->badgeClasses() }}">{{ $row['health']->label() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ implode(', ', \App\Livewire\Settings\DataSources::roleLabels($source)) }} · {{ $source->coverage }} · {{ $source->freshness }}
                                · {{ $source->persistable ? 'may be stored' : 'storage restricted' }}
                            </p>
                            @if ($source->notes)
                                <p class="mt-1 text-xs text-amber-700">{{ $source->notes }}</p>
                            @endif
                        </div>

                        @if ($source->implemented)
                            <button type="button" wire:click="toggle('{{ $row['key'] }}')" role="switch" aria-checked="{{ $row['enabled'] ? 'true' : 'false' }}"
                                    @class(['relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition', 'bg-indigo-600' => $row['enabled'], 'bg-slate-300' => ! $row['enabled']])>
                                <span class="sr-only">{{ $row['enabled'] ? 'Disable' : 'Enable' }} {{ $source->label }}</span>
                                <span @class(['mt-0.5 inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5.5' => $row['enabled'], 'translate-x-0.5' => ! $row['enabled']])></span>
                            </button>
                        @else
                            <span class="text-xs text-slate-500">Not available yet</span>
                        @endif
                    </div>

                    <dl class="grid gap-x-6 gap-y-2 border-t border-slate-100 px-5 py-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs text-slate-500">Usage today</dt>
                            <dd class="tabular-nums">{{ number_format($row['used']) }}{{ $row['limit'] !== null ? ' / '.number_format($row['limit']) : '' }} {{ $source->quotaUnit() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Rate limits</dt>
                            <dd>{{ $source->rateLimit['description'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Last success</dt>
                            <dd>{{ $row['state']->last_success_at?->diffForHumans() ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Data</dt>
                            <dd>{{ $row['stats'] ?? '—' }}</dd>
                        </div>
                        @if ($row['state']->last_error)
                            <div class="sm:col-span-2 lg:col-span-4">
                                <dt class="text-xs text-slate-500">Last error ({{ $row['state']->last_error_at?->diffForHumans() }})</dt>
                                <dd class="text-xs break-all text-rose-700">{{ $row['state']->last_error }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($source->implemented)
                        <div class="grid gap-4 border-t border-slate-100 px-5 py-3 lg:grid-cols-2">
                            @if ($source->credentials !== [])
                                <form wire:submit="saveCredentials('{{ $row['key'] }}')" class="space-y-2">
                                    <p class="text-xs font-medium text-slate-500">Credentials{{ $source->credentialsOptional ? ' (optional)' : '' }}</p>
                                    @foreach ($source->credentials as $field => $fieldLabel)
                                        <div class="flex items-center gap-2">
                                            <label for="cred-{{ $row['key'] }}-{{ $field }}" class="w-40 shrink-0 text-xs text-slate-600">{{ $fieldLabel }}</label>
                                            <input id="cred-{{ $row['key'] }}-{{ $field }}" type="password" autocomplete="off" wire:model="credentialInput.{{ $row['key'] }}.{{ $field }}"
                                                   class="{{ $input }}" placeholder="{{ $row['credentials'][$field] ?? 'Not set' }}">
                                        </div>
                                        @error("credentialInput.{$row['key']}.{$field}") <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                    @endforeach
                                    <div class="flex gap-3">
                                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Save</button>
                                        @if (array_filter($row['credentials']))
                                            <button type="button" wire:click="clearCredentials('{{ $row['key'] }}')" wire:confirm="Remove the stored credentials?" class="text-xs text-rose-600 hover:underline">Remove</button>
                                        @endif
                                    </div>
                                </form>
                            @endif

                            @if ($row['limit'] !== null || $source->rateLimit['daily_quota'] !== null)
                                <form wire:submit="saveQuota('{{ $row['key'] }}')" class="space-y-2">
                                    <label for="quota-{{ $row['key'] }}" class="block text-xs font-medium text-slate-500">Daily budget ({{ $source->quotaUnit() }})</label>
                                    <div class="flex items-center gap-2">
                                        <input id="quota-{{ $row['key'] }}" type="number" min="0" wire:model="quotaInput.{{ $row['key'] }}" class="{{ $input }} max-w-40"
                                               placeholder="{{ $source->rateLimit['daily_quota'] }}">
                                        <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Save</button>
                                    </div>
                                    @error("quotaInput.{$row['key']}") <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                    <p class="text-xs text-slate-500">Never exceeded; the source pauses until tomorrow once used.</p>
                                </form>
                            @endif

                            @if ($row['key'] === 'nsi')
                                <div>
                                    <button type="button" wire:click="refreshBrands" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Refresh brand registry now</button>
                                    <p class="mt-1 text-xs text-slate-500">Downloads the configured NSI category files (runs weekly by itself).</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">
                        Capabilities: {{ implode(', ', array_map(fn ($capability) => str_replace('_', ' ', $capability), $source->capabilities)) }}
                        @if ($source->docsUrl)
                            · <a href="{{ $source->docsUrl }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">Documentation ↗</a>
                        @endif
                    </p>
                </div>
            @endforeach
        </section>
    @endforeach
</div>

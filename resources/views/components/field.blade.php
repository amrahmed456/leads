@props(['label'])

<div class="px-5 py-3 sm:grid sm:grid-cols-3 sm:gap-4">
    <dt class="text-sm font-medium text-slate-500">{{ $label }}</dt>
    <dd class="mt-1 text-sm break-words text-slate-900 sm:col-span-2 sm:mt-0">
        @if ($slot->hasActualContent())
            {{ $slot }}
        @else
            <span class="text-slate-400">—</span>
        @endif
    </dd>
</div>

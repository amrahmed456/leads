@props(['label', 'value' => null, 'detail' => null])

<div class="px-5 py-2.5 sm:grid sm:grid-cols-3 sm:gap-4">
    <dt class="text-sm font-medium text-slate-500">{{ $label }}</dt>
    <dd class="mt-1 flex flex-wrap items-baseline gap-x-2 text-sm sm:col-span-2 sm:mt-0">
        @if ($value === true)
            <span class="font-medium text-emerald-700"><span aria-hidden="true">✓</span> Yes</span>
        @elseif ($value === false)
            <span class="font-medium text-slate-500"><span aria-hidden="true">✗</span> No</span>
        @else
            <span class="text-slate-400">Unknown</span>
        @endif

        @if (filled($detail))
            <span class="text-xs break-all text-slate-500" dir="auto">{{ $detail }}</span>
        @endif
    </dd>
</div>

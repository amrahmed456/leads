@props(['label', 'score', 'breakdown' => [], 'hint' => null])

<div {{ $attributes->class('px-5 py-4') }}>
    <div class="flex items-baseline justify-between gap-3">
        <span class="text-sm font-medium text-slate-600">{{ $label }}</span>
        <span class="text-xl font-semibold tabular-nums">{{ $score }}<span class="text-xs font-normal text-slate-400"> / 100</span></span>
    </div>
    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div @class(['h-full rounded-full', 'bg-emerald-500' => $score >= 60, 'bg-amber-500' => $score >= 35 && $score < 60, 'bg-rose-500' => $score < 35])
             style="width: {{ max(0, min(100, $score)) }}%"></div>
    </div>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($breakdown)
        <details class="mt-2">
            <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-700">How it was calculated</summary>
            <ul class="mt-1 space-y-0.5 text-xs">
                @foreach ($breakdown as $item)
                    <li class="flex justify-between gap-3">
                        <span class="text-slate-600" dir="auto">{{ $item['label'] }}</span>
                        <span @class(['shrink-0 font-medium tabular-nums', 'text-emerald-700' => $item['points'] > 0, 'text-rose-700' => $item['points'] < 0, 'text-slate-400' => $item['points'] === 0])>{{ $item['points'] > 0 ? '+' : '' }}{{ $item['points'] }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

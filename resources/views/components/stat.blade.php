@props(['label', 'value', 'href' => null, 'hint' => null])

<div {{ $attributes->class('rounded-lg border border-slate-200 bg-white p-5 shadow-sm') }}>
    <dt class="text-sm font-medium text-slate-500">{{ $label }}</dt>
    <dd class="mt-1 text-3xl font-semibold tracking-tight tabular-nums">
        @if ($href)
            <a href="{{ $href }}" class="hover:text-indigo-600">{{ is_numeric($value) ? number_format($value) : $value }}</a>
        @else
            {{ is_numeric($value) ? number_format($value) : $value }}
        @endif
    </dd>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>

@props(['score' => null])

@if ($score)
    @php
        $classes = match ($score->grade) {
            \App\Enums\LeadGrade::Hot => 'bg-rose-600 text-white',
            \App\Enums\LeadGrade::Warm => 'bg-amber-100 text-amber-800',
            \App\Enums\LeadGrade::Cold => 'bg-slate-100 text-slate-600',
        };
    @endphp

    <span {{ $attributes->class(['inline-flex min-w-9 justify-center rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', $classes]) }} title="{{ $score->grade->label() }} lead">{{ $score->score }}</span>
@else
    <span class="text-xs text-slate-400" title="Not scored yet">—</span>
@endif

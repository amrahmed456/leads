@props(['score' => null])

@if ($score)
    <span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap']) }}
          title="{{ $score->status->label() }} · confidence {{ $score->data_confidence_score }}, fit {{ $score->business_fit_score }}, opportunity {{ $score->opportunity_score }}">
        <span class="inline-flex min-w-9 justify-center rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums {{ $score->status->badgeClasses() }}">{{ $score->score }}</span>
        <span class="text-xs text-slate-500">{{ $score->status->label() }}</span>
    </span>
@else
    <span class="text-xs text-slate-400" title="Not scored yet">—</span>
@endif

@props(['priority'])

@php
    [$icon, $classes] = match ($priority) {
        \App\Enums\OpportunityPriority::High => ['🔥', 'bg-rose-50 text-rose-700 ring-rose-600/20'],
        \App\Enums\OpportunityPriority::Medium => ['🟠', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        \App\Enums\OpportunityPriority::Low => ['🟡', 'bg-yellow-50 text-yellow-800 ring-yellow-600/20'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset', $classes]) }}>
    <span aria-hidden="true">{{ $icon }}</span>{{ $priority->label() }}
</span>

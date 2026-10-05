@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\CampaignStatus::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        \App\Enums\CampaignStatus::Running => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        \App\Enums\CampaignStatus::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        \App\Enums\CampaignStatus::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap', $classes]) }}>
    @if ($status->isActive())
        <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
    @endif
    {{ $status->label() }}
</span>

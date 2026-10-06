@props(['type' => null, 'confidence' => null])

@if ($type)
    <span {{ $attributes->class([
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
        'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $type->isIndependentKind(),
        'bg-rose-50 text-rose-700 ring-rose-600/20' => $type->isChainKind() || in_array($type, [\App\Enums\EntityType::Government, \App\Enums\EntityType::Aggregator, \App\Enums\EntityType::Directory], true),
        'bg-slate-50 text-slate-600 ring-slate-500/20' => in_array($type, [\App\Enums\EntityType::Unknown, \App\Enums\EntityType::Nonprofit], true),
    ]) }} @if ($confidence !== null) title="{{ $confidence }}% confidence" @endif>{{ $type->label() }}</span>
@else
    <span class="text-xs text-slate-400">Not classified</span>
@endif

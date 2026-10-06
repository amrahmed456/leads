<?php

namespace App\Enums;

/**
 * Final, explainable qualification of a business as a sales prospect.
 */
enum LeadStatus: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Low = 'low';
    case ReviewRequired = 'review_required';
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::Hot => 'Hot',
            self::Warm => 'Warm',
            self::Low => 'Low',
            self::ReviewRequired => 'Review required',
            self::Disqualified => 'Disqualified',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Hot => 'bg-rose-600 text-white',
            self::Warm => 'bg-amber-100 text-amber-800',
            self::Low => 'bg-slate-100 text-slate-600',
            self::ReviewRequired => 'bg-sky-100 text-sky-800',
            self::Disqualified => 'bg-slate-700 text-white',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}

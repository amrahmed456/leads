<?php

namespace App\Enums;

enum SourceHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case QuotaExhausted = 'quota_exhausted';
    case Unconfigured = 'unconfigured';
    case Disabled = 'disabled';
    case NotImplemented = 'not_implemented';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Failing => 'Failing',
            self::QuotaExhausted => 'Daily quota used',
            self::Unconfigured => 'Needs credentials',
            self::Disabled => 'Disabled',
            self::NotImplemented => 'Coming later',
            self::Unknown => 'Not used yet',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Healthy => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Degraded, self::QuotaExhausted, self::Unconfigured => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Failing => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            default => 'bg-slate-50 text-slate-600 ring-slate-500/20',
        };
    }
}

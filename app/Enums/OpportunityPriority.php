<?php

namespace App\Enums;

enum OpportunityPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Higher is more urgent; used for sorting.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}

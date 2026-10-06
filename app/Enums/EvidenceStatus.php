<?php

namespace App\Enums;

enum EvidenceStatus: string
{
    case Active = 'active';
    case Stale = 'stale';
    case Conflicting = 'conflicting';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

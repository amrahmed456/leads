<?php

namespace App\Enums;

enum LeadGrade: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

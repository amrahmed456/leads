<?php

namespace App\Enums;

/**
 * How widely a brand operates.
 */
enum BrandScope: string
{
    case International = 'international';
    case National = 'national';
    case Regional = 'regional';
    case Local = 'local';
    case Unknown = 'unknown';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

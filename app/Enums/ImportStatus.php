<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}

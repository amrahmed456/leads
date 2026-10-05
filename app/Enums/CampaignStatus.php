<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether a discovery run is queued or in progress.
     */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}

<?php

namespace App\Enums;

enum AuditStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::NotApplicable => 'No website',
        };
    }

    /**
     * Whether the audit is queued or in progress.
     */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}

<?php

namespace App\Enums;

/**
 * Status of one discovery source within a campaign run.
 */
enum SourceRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Skipped], true);
    }
}

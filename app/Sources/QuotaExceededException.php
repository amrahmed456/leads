<?php

namespace App\Sources;

use RuntimeException;

/**
 * The source's daily quota is used up. Never retried the same day.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly int $used,
        public readonly int $limit,
        string $unit = 'units',
    ) {
        parent::__construct("The daily quota of [{$source}] is used up ({$used}/{$limit} {$unit}). It resets tomorrow.");
    }
}

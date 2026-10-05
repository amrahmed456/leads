<?php

namespace App\Enums;

/**
 * How strongly the evidence supports a detected website signal.
 */
enum Confidence: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public function atLeast(self $confidence): bool
    {
        return $this->rank() >= $confidence->rank();
    }

    public function max(self $confidence): self
    {
        return $confidence->rank() > $this->rank() ? $confidence : $this;
    }
}

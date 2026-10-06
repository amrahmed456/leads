<?php

namespace App\Identity;

use App\Enums\EntityType;
use App\Enums\ReasonCode;

/**
 * The outcome of entity classification, with every reason behind it.
 */
final readonly class EntityClassification
{
    /**
     * @param  int  $confidence  0-100
     * @param  list<array{code: string, label: string, weak?: bool}>  $reasons  Decisive reasons first; weak hints are flagged
     */
    public function __construct(
        public EntityType $type,
        public int $confidence,
        public array $reasons,
    ) {}

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->reasons, 'code')));
    }

    /**
     * Chain hints too weak to classify on (a resembling brand name, a shared phone).
     *
     * @return list<array{code: string, label: string, weak?: bool}>
     */
    public function weakSignals(): array
    {
        return array_values(array_filter($this->reasons, fn (array $reason): bool => $reason['weak'] ?? false));
    }

    public function has(ReasonCode $code): bool
    {
        return in_array($code->value, $this->codes(), true);
    }
}

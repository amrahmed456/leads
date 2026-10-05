<?php

namespace App\Auditing\Data;

/**
 * The technical / digital presence score of a website and how it was earned.
 */
final readonly class AuditScore
{
    /**
     * @param  int  $score  Share of applicable points earned, 0-100
     * @param  list<array{key: string, label: string, points: int, max: int, passed: bool}>  $checks
     */
    public function __construct(
        public int $score,
        public array $checks,
    ) {}

    /**
     * @return list<string>
     */
    public function positives(): array
    {
        return $this->labels(passed: true);
    }

    /**
     * @return list<string>
     */
    public function negatives(): array
    {
        return $this->labels(passed: false);
    }

    /**
     * @return array{checks: list<array{key: string, label: string, points: int, max: int, passed: bool}>}
     */
    public function toArray(): array
    {
        return ['checks' => $this->checks];
    }

    /**
     * @return list<string>
     */
    private function labels(bool $passed): array
    {
        return array_values(array_map(
            fn (array $check): string => $check['label'],
            array_filter($this->checks, fn (array $check): bool => $check['passed'] === $passed),
        ));
    }
}

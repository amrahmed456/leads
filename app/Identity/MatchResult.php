<?php

namespace App\Identity;

final readonly class MatchResult
{
    /**
     * @param  int  $confidence  0-100
     * @param  list<string>  $reasons  Why the records were (or were not) matched
     */
    public function __construct(
        public bool $matched,
        public int $confidence,
        public array $reasons,
        public ?float $distanceMeters = null,
        public float $nameSimilarity = 0.0,
    ) {}

    /**
     * @param  list<string>  $reasons
     */
    public static function noMatch(array $reasons, ?float $distance = null, float $similarity = 0.0): self
    {
        return new self(false, 0, $reasons, $distance, $similarity);
    }
}

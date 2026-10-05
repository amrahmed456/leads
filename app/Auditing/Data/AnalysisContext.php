<?php

namespace App\Auditing\Data;

use App\Enums\Country;

/**
 * What detectors know besides the page itself.
 */
final readonly class AnalysisContext
{
    /**
     * @param  list<DetectedTechnology>  $technologies
     */
    public function __construct(
        public ?Country $country,
        public array $technologies,
        public int $currentYear,
    ) {}

    /**
     * @return list<DetectedTechnology>
     */
    public function technologiesIn(string ...$categories): array
    {
        return array_values(array_filter(
            $this->technologies,
            fn (DetectedTechnology $technology): bool => in_array($technology->category, $categories, true),
        ));
    }
}

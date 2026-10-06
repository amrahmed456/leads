<?php

namespace App\Identity\Evidence;

use App\Enums\EvidenceFact;

/**
 * One fact to record as evidence, before provenance is attached.
 */
final readonly class EvidenceItem
{
    /**
     * @param  float|null  $confidence  0-1, the source's own confidence in this fact
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public EvidenceFact $fact,
        public string $value,
        public ?float $confidence = null,
        public array $metadata = [],
    ) {}
}

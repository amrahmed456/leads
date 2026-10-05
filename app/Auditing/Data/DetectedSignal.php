<?php

namespace App\Auditing\Data;

use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

final readonly class DetectedSignal
{
    /**
     * @param  list<string>  $evidence  Short human-readable reasons, e.g. 'Link to wa.me/966500000000'
     * @param  array<string, mixed>  $data  Extracted values, e.g. phone numbers or platforms
     */
    public function __construct(
        public WebsiteSignal $signal,
        public Confidence $confidence,
        public array $evidence = [],
        public array $data = [],
    ) {}

    /**
     * A signal counts as present from medium confidence; low confidence
     * (e.g. a word in the text) is kept only as a hint.
     */
    public function isDetected(): bool
    {
        return $this->confidence->atLeast(Confidence::Medium);
    }

    /**
     * @return array{confidence: string, evidence: list<string>, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'confidence' => $this->confidence->value,
            'evidence' => $this->evidence,
            'data' => $this->data,
        ];
    }
}

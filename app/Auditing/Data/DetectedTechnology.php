<?php

namespace App\Auditing\Data;

use App\Enums\Confidence;

final readonly class DetectedTechnology
{
    /**
     * @param  string  $category  cms, site_builder, ecommerce, page_builder, framework, analytics or advertising
     */
    public function __construct(
        public string $name,
        public string $category,
        public Confidence $confidence,
        public string $evidence,
    ) {}

    /**
     * @return array{name: string, category: string, confidence: string, evidence: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'confidence' => $this->confidence->value,
            'evidence' => $this->evidence,
        ];
    }
}

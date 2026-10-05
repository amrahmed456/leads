<?php

namespace App\Auditing\Data;

use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Everything deterministically detected on a homepage.
 */
final readonly class WebsiteAnalysis
{
    /**
     * @param  list<DetectedTechnology>  $technologies
     * @param  array<string, DetectedSignal>  $signals  Keyed by WebsiteSignal value
     */
    public function __construct(
        public ?string $title,
        public ?string $metaDescription,
        public LanguageProfile $languages,
        public array $technologies,
        public ?string $cms,
        public array $signals,
    ) {}

    public function signal(WebsiteSignal $signal): DetectedSignal
    {
        return $this->signals[$signal->value] ?? new DetectedSignal($signal, Confidence::None);
    }

    public function has(WebsiteSignal $signal): bool
    {
        return $this->signal($signal)->isDetected();
    }

    /**
     * @return array<string, array{confidence: string, evidence: list<string>, data: array<string, mixed>}>
     */
    public function signalsToArray(): array
    {
        return array_map(fn (DetectedSignal $signal): array => $signal->toArray(), $this->signals);
    }
}

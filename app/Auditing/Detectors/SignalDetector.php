<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\Data\DetectedSignal;
use App\Auditing\HtmlDocument;

/**
 * Detects one or more website signals with deterministic rules.
 */
interface SignalDetector
{
    /**
     * @return list<DetectedSignal>
     */
    public function detect(HtmlDocument $document, AnalysisContext $context): array;
}

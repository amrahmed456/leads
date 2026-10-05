<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\DetectedSignal;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Collects evidence for one signal. The strongest piece of evidence sets the
 * confidence, and two different medium-strength clues together count as high.
 */
final class SignalBuilder
{
    private const MAX_EVIDENCE = 5;

    private const MAX_VALUES = 5;

    private Confidence $confidence = Confidence::None;

    /** @var list<string> */
    private array $evidence = [];

    private int $mediumClues = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly WebsiteSignal $signal) {}

    public function add(Confidence $confidence, string $evidence): self
    {
        if (in_array($evidence, $this->evidence, true)) {
            return $this;
        }

        if ($confidence === Confidence::Medium) {
            $this->mediumClues++;
        }

        $this->confidence = $this->confidence->max($confidence);

        if (count($this->evidence) < self::MAX_EVIDENCE) {
            $this->evidence[] = $evidence;
        }

        return $this;
    }

    /**
     * Record weak evidence (e.g. a keyword in the text) only when nothing
     * stronger was found, so it never outweighs real elements.
     */
    public function hint(string $evidence): self
    {
        return $this->confidence() === Confidence::None ? $this->add(Confidence::Low, $evidence) : $this;
    }

    public function confidence(): Confidence
    {
        return $this->confidence === Confidence::Medium && $this->mediumClues >= 2 ? Confidence::High : $this->confidence;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Append a value to a list in the signal data (deduplicated, capped).
     */
    public function collect(string $key, string $value): self
    {
        $values = $this->data[$key] ?? [];

        if (! in_array($value, $values, true) && count($values) < self::MAX_VALUES) {
            $values[] = $value;
        }

        $this->data[$key] = $values;

        return $this;
    }

    public function build(): DetectedSignal
    {
        return new DetectedSignal($this->signal, $this->confidence(), $this->evidence, $this->data);
    }
}

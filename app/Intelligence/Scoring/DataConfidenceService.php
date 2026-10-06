<?php

namespace App\Intelligence\Scoring;

use App\Identity\Evidence\ReconciliationResult;
use App\Models\Business;
use App\Models\WebsiteAudit;
use App\Support\Normalization\Text;

/**
 * "How confident are we that this business and its core data are real
 * and current?" — from 0 to 100, every point explained.
 *
 * Independent source groups count, not sources: OpenStreetMap plus
 * Geoapify is one group. This score never measures buying intent and never
 * makes a business a better lead by itself.
 */
final class DataConfidenceService
{
    /**
     * @param  array{points: array<string, int>, minor_conflict_cap: int, source_confidence_high: float, source_confidence_low: float}  $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly int $recentWithinDays,
    ) {}

    public function score(Business $business, ReconciliationResult $evidence, ?WebsiteAudit $audit): ScoreResult
    {
        $items = [];
        $groups = count($evidence->existenceGroups);

        if ($groups > 0) {
            $labels = implode(', ', array_map(fn (string $group): string => str_replace('_', ' ', $group), $evidence->existenceGroups));
            $items[] = $this->item('groups_'.min(3, $groups), sprintf('%d independent source %s confirm it exists (%s)', $groups, $groups === 1 ? 'group' : 'groups', $labels));
        } elseif ($evidence->staleOnly) {
            $items[] = $this->item('stale', 'Only stale observations');
        }

        if ($evidence->hasFirstPartyConfirmation()) {
            $items[] = $this->item('first_party', 'Confirmed by a first-party source (own website or official store locator)');
        }

        if ($evidence->phoneCorroborated) {
            $items[] = $this->item('phone_corroborated', 'Phone number matches across independent sources');
        }

        if ($evidence->locationCorroborated) {
            $items[] = $this->item('location_corroborated', 'Location matches across independent sources');
        }

        if ($business->last_verified_at !== null && $business->last_verified_at->greaterThan(now()->subDays($this->recentWithinDays))) {
            $items[] = $this->item('recently_seen', 'Observed in the last '.$this->recentWithinDays.' days');
        }

        $items = [...$items, ...$this->status($business), ...$this->sourceConfidence($evidence)];

        if ($audit?->isAnalyzed()) {
            $items[] = $this->item('website_working', 'Its website works');
        } elseif ($audit?->showsBrokenWebsite()) {
            $items[] = $this->item('website_broken', 'Its listed website is broken');
        }

        if ($evidence->hasMajorConflict()) {
            $items[] = $this->item('major_conflict', 'Sources disagree on its identity');
        }

        if (($minor = $evidence->minorConflictCount()) > 0) {
            $items[] = [
                'key' => 'minor_conflict',
                'label' => "{$minor} minor data conflict(s) between sources",
                'points' => max(-$this->rules['minor_conflict_cap'], $minor * $this->points('minor_conflict')),
            ];
        }

        if ($business->latitude === null || $business->longitude === null) {
            $items[] = $this->item('no_coordinates', 'No location coordinates');
        }

        $key = Text::matchKey($business->name);
        if (mb_strlen(str_replace(' ', '', $key)) <= 2 || ctype_digit(str_replace(' ', '', $key))) {
            $items[] = $this->item('junk_name', 'The name does not look like a business name');
        }

        return ScoreResult::fromBreakdown($items);
    }

    /**
     * @return list<array{key: string, label: string, points: int}>
     */
    private function status(Business $business): array
    {
        return match ($business->operating_status) {
            'open' => [$this->item('operating_open', 'Reported open')],
            'permanently_closed' => [$this->item('permanently_closed', 'Reported permanently closed')],
            default => [],
        };
    }

    /**
     * The sources' own existence confidence (Overture), never lead quality.
     *
     * @return list<array{key: string, label: string, points: int}>
     */
    private function sourceConfidence(ReconciliationResult $evidence): array
    {
        $confidence = $evidence->sourceConfidence;

        return match (true) {
            $confidence === null => [],
            $confidence >= $this->rules['source_confidence_high'] => [$this->item('source_confidence_high', sprintf('High source confidence that the place exists (%.2f)', $confidence))],
            $confidence < $this->rules['source_confidence_low'] => [$this->item('source_confidence_low', sprintf('Low source confidence that the place exists (%.2f)', $confidence))],
            default => [],
        };
    }

    /**
     * @return array{key: string, label: string, points: int}
     */
    private function item(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'points' => $this->points($key)];
    }

    private function points(string $key): int
    {
        return (int) ($this->rules['points'][$key] ?? 0);
    }
}

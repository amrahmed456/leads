<?php

namespace App\Identity\Evidence;

use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Identity\NameSimilarity;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Reconciles everything the sources say about a business:
 *
 * - marks evidence not seen for a long time as stale;
 * - counts the independent source groups confirming the business exists
 *   (OSM and Geoapify are one group, never two);
 * - finds conflicts between groups (different names, brands, websites),
 *   picks the preferred value by source priority and keeps the others,
 *   marked "conflicting", with their provenance;
 * - fills fields the business is missing from preferred evidence. The
 *   business's name is never overwritten.
 */
final class EvidenceReconciler
{
    private const CONFLICT_FACTS = [EvidenceFact::Name, EvidenceFact::Brand, EvidenceFact::BrandWikidata, EvidenceFact::Website];

    private const FILLABLE = ['phone' => EvidenceFact::Phone, 'website' => EvidenceFact::Website, 'email' => EvidenceFact::Email, 'address' => EvidenceFact::Address];

    /**
     * @param  list<string>  $sourcePriority  Preferred providers first
     */
    public function __construct(
        private readonly array $sourcePriority,
        private readonly int $staleAfterDays,
    ) {}

    public function reconcile(Business $business): ReconciliationResult
    {
        $business->evidence()
            ->where('status', '!=', EvidenceStatus::Stale->value)
            ->where('last_seen_at', '<', now()->subDays($this->staleAfterDays))
            ->update(['status' => EvidenceStatus::Stale->value]);

        $all = $business->evidence()->get();
        $evidence = $all->reject(fn (BusinessEvidence $item): bool => $item->status === EvidenceStatus::Stale)->values();

        $conflicts = [];
        foreach (self::CONFLICT_FACTS as $fact) {
            $conflict = $this->conflict($fact, $evidence->where('fact_type', $fact)->reject(fn (BusinessEvidence $item): bool => (bool) ($item->metadata['alternate'] ?? false)));

            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        $existence = $evidence->filter(fn (BusinessEvidence $item): bool => $item->fact_type === EvidenceFact::Existence && $item->source_role->confirmsExistence());
        $groups = $existence->pluck('independence_group')->unique()->values()->all();

        $this->fillMissing($business, $evidence);

        $business->forceFill([
            'evidence_count' => $evidence->count(),
            'independent_source_count' => count($groups),
            'has_identity_conflict' => collect($conflicts)->contains('severity', 'major'),
            'identity_conflicts' => $conflicts === [] ? null : $conflicts,
            'last_verified_at' => $existence->max('last_seen_at'),
        ])->save();

        return new ReconciliationResult(
            evidence: $evidence,
            existenceGroups: $groups,
            conflicts: $conflicts,
            phoneCorroborated: $this->corroborated($evidence->where('fact_type', EvidenceFact::Phone)),
            locationCorroborated: $this->locationCorroborated($evidence->where('fact_type', EvidenceFact::Coordinates)),
            sourceConfidence: $existence->whereNotNull('confidence')->where('provider', '!=', 'website')->min('confidence'),
            staleOnly: $existence->isEmpty() && $all->contains('fact_type', EvidenceFact::Existence),
        );
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $items
     * @return array{fact: string, severity: string, values: list<array{value: string, provider: string, group: string}>, preferred: string}|null
     */
    private function conflict(EvidenceFact $fact, Collection $items): ?array
    {
        if ($items->pluck('independence_group')->unique()->count() < 2) {
            $this->setStatus($items, EvidenceStatus::Active);

            return null;
        }

        // Group the values that mean the same thing.
        $clusters = [];
        foreach ($items->sortBy(fn (BusinessEvidence $item): int => $this->priority($item->provider)) as $item) {
            foreach ($clusters as $index => $cluster) {
                if ($this->same($fact, $cluster[0], $item)) {
                    $clusters[$index][] = $item;

                    continue 2;
                }
            }

            $clusters[] = [$item];
        }

        if (count($clusters) < 2) {
            $this->setStatus($items, EvidenceStatus::Active);

            return null;
        }

        // A name only conflicts when no group's names resemble another group's at all.
        if ($fact === EvidenceFact::Name && ! $this->groupsDisagreeOnName($items)) {
            $this->setStatus($items, EvidenceStatus::Active);

            return null;
        }

        $preferred = $clusters[0];
        $this->setStatus(collect($preferred), EvidenceStatus::Active);
        $this->setStatus(collect(array_merge(...array_slice($clusters, 1))), EvidenceStatus::Conflicting);

        return [
            'fact' => $fact->value,
            'severity' => $this->severity($fact, $clusters),
            'values' => $items->map(fn (BusinessEvidence $item): array => [
                'value' => $item->value, 'provider' => $item->provider, 'group' => $item->independence_group,
            ])->unique(fn (array $value): string => $value['value'].'|'.$value['provider'])->values()->all(),
            'preferred' => $preferred[0]->value,
        ];
    }

    /**
     * @param  list<list<BusinessEvidence>>  $clusters
     */
    private function severity(EvidenceFact $fact, array $clusters): string
    {
        if ($fact === EvidenceFact::BrandWikidata) {
            return 'major';
        }

        if ($fact === EvidenceFact::Name) {
            $strong = array_filter($clusters, fn (array $cluster): bool => collect($cluster)->contains(fn (BusinessEvidence $item): bool => $item->source_role->confirmsExistence()));

            return count($strong) >= 2 ? 'major' : 'minor';
        }

        return 'minor';
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $items
     */
    private function groupsDisagreeOnName(Collection $items): bool
    {
        $byGroup = $items->groupBy('independence_group')->map(fn (Collection $group): array => $group->pluck('value')->all())->values()->all();

        foreach ($byGroup as $i => $names) {
            foreach ($byGroup as $j => $otherNames) {
                if ($i >= $j) {
                    continue;
                }

                $best = 0.0;
                foreach ($names as $name) {
                    foreach ($otherNames as $other) {
                        $best = max($best, NameSimilarity::score($name, $other));
                    }
                }

                if ($best < 0.5) {
                    return true;
                }
            }
        }

        return false;
    }

    private function same(EvidenceFact $fact, BusinessEvidence $a, BusinessEvidence $b): bool
    {
        return match ($fact) {
            EvidenceFact::Name => NameSimilarity::score($a->value, $b->value) >= 0.75,
            EvidenceFact::Brand => $a->value_normalized === $b->value_normalized || NameSimilarity::score($a->value, $b->value) >= 0.9,
            default => $a->value_normalized === $b->value_normalized,
        };
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $evidence
     */
    private function fillMissing(Business $business, Collection $evidence): void
    {
        foreach (self::FILLABLE as $attribute => $fact) {
            if ($business->getAttribute($attribute) !== null) {
                continue;
            }

            $preferred = $evidence->where('fact_type', $fact)->where('status', EvidenceStatus::Active)
                ->sortBy(fn (BusinessEvidence $item): int => $this->priority($item->provider))
                ->first();

            if ($preferred !== null && ($attribute !== 'website' || $preferred->provider !== 'website')) {
                $business->setAttribute($attribute, $preferred->value);
            }
        }

        $status = $evidence->where('fact_type', EvidenceFact::OperatingStatus)
            ->sortBy(fn (BusinessEvidence $item): int => $this->priority($item->provider))
            ->first();

        if ($status !== null) {
            $business->operating_status = $status->value;
        }
    }

    /**
     * The same value attested by two independent groups.
     *
     * @param  Collection<int, BusinessEvidence>  $items
     */
    private function corroborated(Collection $items): bool
    {
        return $items->groupBy('value_normalized')->contains(fn (Collection $same): bool => $same->pluck('independence_group')->unique()->count() >= 2);
    }

    /**
     * Two independent groups placing the business within 100 m of each other.
     *
     * @param  Collection<int, BusinessEvidence>  $items
     */
    private function locationCorroborated(Collection $items): bool
    {
        $points = $items->values();

        foreach ($points as $i => $a) {
            foreach ($points as $j => $b) {
                if ($j <= $i || $a->independence_group === $b->independence_group) {
                    continue;
                }

                [$latA, $lonA] = array_map('floatval', explode(',', $a->value_normalized));
                [$latB, $lonB] = array_map('floatval', explode(',', $b->value_normalized));

                if (Geo::distanceMeters($latA, $lonA, $latB, $lonB) <= 100) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $items
     */
    private function setStatus(Collection $items, EvidenceStatus $status): void
    {
        $ids = $items->filter(fn (BusinessEvidence $item): bool => $item->status !== $status)
            ->map(fn (BusinessEvidence $item): int => $item->getKey())
            ->values()
            ->all();

        if ($ids !== []) {
            BusinessEvidence::query()->whereKey($ids)->update(['status' => $status->value]);
            $items->each(fn (BusinessEvidence $item) => $item->status = $status);
        }
    }

    private function priority(string $provider): int
    {
        $index = array_search($provider, $this->sourcePriority, true);

        return $index === false ? count($this->sourcePriority) : $index;
    }
}

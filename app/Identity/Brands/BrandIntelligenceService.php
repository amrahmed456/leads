<?php

namespace App\Identity\Brands;

use App\Enums\EvidenceFact;
use App\Enums\RelationshipType;
use App\Jobs\EnrichBusinessBrand;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\EntityRelationship;
use Illuminate\Support\Collection;

/**
 * Detects which brand a business belongs to, and how sure that is.
 *
 * Claims come from the business's evidence (OSM brand tags, Overture
 * brand, AllThePlaces store locators, the website's Schema.org brand) and
 * from the brand registry (NSI, Wikidata). Strength:
 *
 * - strong: a brand Wikidata ID, or a first-party store locator branch
 * - medium: a source explicitly tags the brand, or the name exactly equals
 *   a registered brand operating in the country
 * - weak:   the name merely contains a registered brand name
 *
 * The business keeps its own name; the brand is stored separately.
 */
final class BrandIntelligenceService
{
    private const PROVIDER_PRIORITY = ['alltheplaces', 'website', 'overture', 'openstreetmap', 'geoapify'];

    public function __construct(
        private readonly BrandRegistry $registry,
        private readonly BrandEnricher $enricher,
    ) {}

    /**
     * @param  Collection<int, BusinessEvidence>  $evidence  The business's current evidence
     */
    public function assess(Business $business, Collection $evidence): BrandAssessment
    {
        $claims = $this->claims($business, $evidence);
        // Strongest first; then claims with a Wikidata ID; then by provider priority.
        usort($claims, fn (BrandClaim $a, BrandClaim $b): int => [$b->strength, (int) ($b->wikidataId !== null), $this->priority($a->provider)]
            <=> [$a->strength, (int) ($a->wikidataId !== null), $this->priority($b->provider)]);

        $best = $claims[0] ?? null;
        $possible = $this->possibleBrands($business, $best);

        if ($best === null || $best->strength < BrandClaim::MEDIUM) {
            $this->store($business, null, 0, null);

            return new BrandAssessment(null, $best?->strength ?? 0, $best === null ? 0 : 30, $claims, $possible);
        }

        $brand = $this->registry->resolve($best->name, $best->wikidataId, $best->provider);

        // Other independent groups naming the same brand raise confidence.
        $groups = [];
        foreach ($claims as $claim) {
            if ($claim->strength >= BrandClaim::MEDIUM && $this->sameBrand($claim, $best)) {
                $groups[$claim->independenceGroup] = true;
            }
        }

        $base = match (true) {
            $best->strength >= BrandClaim::STRONG => 85,
            $best->provider === 'brand_registry' => 60,
            default => 65,
        };
        $confidence = min(99, $base + 5 * min(3, count($groups) - 1));

        $this->store($business, $brand, $confidence, $best->provider);

        if ($this->enricher->needsEnrichment($brand)) {
            rescue(fn () => EnrichBusinessBrand::dispatch($brand), report: false);
        }

        return new BrandAssessment($brand, $best->strength, $confidence, $claims, $possible);
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $evidence
     * @return list<BrandClaim>
     */
    private function claims(Business $business, Collection $evidence): array
    {
        $claims = [];
        $brandNames = $evidence->where('fact_type', EvidenceFact::Brand)->groupBy('provider');

        foreach ($evidence->where('fact_type', EvidenceFact::BrandWikidata) as $item) {
            $name = $brandNames->get($item->provider)?->first()?->value
                ?? $this->registry->findByWikidata($item->value)?->name
                ?? $item->value;

            $claims[] = new BrandClaim($name, $item->value, $item->provider, $item->independence_group, BrandClaim::STRONG,
                "{$item->providerLabel()} identifies the brand as {$name} ({$item->value})");
        }

        foreach ($evidence->where('fact_type', EvidenceFact::Brand) as $item) {
            $firstParty = $item->provider === 'alltheplaces';

            $claims[] = new BrandClaim($item->value, null, $item->provider, $item->independence_group,
                $firstParty ? BrandClaim::STRONG : BrandClaim::MEDIUM,
                $firstParty
                    ? "Listed as a {$item->value} branch in the brand's official store locator"
                    : "{$item->providerLabel()} tags the brand as {$item->value}");
        }

        foreach ([$business->name, $business->alternate_name] as $name) {
            $registered = $name === null ? null : $this->registry->findByName($name, $business->country_code);

            if ($registered !== null && ($registered->nsi_id !== null || $registered->wikidata_id !== null)) {
                $local = $this->registry->operatesIn($registered, $business->country_code);

                $claims[] = new BrandClaim($registered->name, $registered->wikidata_id, 'brand_registry', 'brand_registry',
                    $local ? BrandClaim::MEDIUM : BrandClaim::WEAK,
                    $local
                        ? "The name matches the registered brand {$registered->name}"
                        : "The name matches the brand {$registered->name}, which is not registered in this country");

                break;
            }
        }

        return $claims;
    }

    /**
     * Registered brands the name only resembles: never classification evidence on their own.
     *
     * @return list<string>
     */
    private function possibleBrands(Business $business, ?BrandClaim $best): array
    {
        $names = [];

        foreach ($this->registry->findContainedIn($business->name) as $brand) {
            if (($best === null || ! $this->sameBrand(new BrandClaim($brand->name, $brand->wikidata_id, '', '', 0, ''), $best))
                && mb_strlen($brand->name_normalized) >= 4 && $this->registry->operatesIn($brand, $business->country_code)) {
                $names[] = $brand->name;
            }
        }

        return array_values(array_unique($names));
    }

    private function sameBrand(BrandClaim $a, BrandClaim $b): bool
    {
        if ($a->wikidataId !== null && $b->wikidataId !== null) {
            return strcasecmp($a->wikidataId, $b->wikidataId) === 0;
        }

        return $this->registry->findByName($a->name)?->is($this->registry->findByName($b->name)) === true
            || mb_strtolower(trim($a->name)) === mb_strtolower(trim($b->name));
    }

    private function store(Business $business, ?Brand $brand, int $confidence, ?string $provider): void
    {
        $business->forceFill([
            'brand_id' => $brand?->getKey(),
            'brand_confidence' => $brand === null ? null : $confidence,
            'brand_source' => $provider,
        ])->save();

        $edges = EntityRelationship::query()
            ->where('subject_type', $business->getMorphClass())
            ->where('subject_id', $business->getKey())
            ->where('relationship', RelationshipType::BranchOf);

        if ($brand === null) {
            $edges->delete();

            return;
        }

        (clone $edges)->where(fn ($query) => $query->where('object_type', '!=', $brand->getMorphClass())->orWhere('object_id', '!=', $brand->getKey()))->delete();

        EntityRelationship::query()->updateOrCreate([
            'subject_type' => $business->getMorphClass(), 'subject_id' => $business->getKey(),
            'relationship' => RelationshipType::BranchOf,
            'object_type' => $brand->getMorphClass(), 'object_id' => $brand->getKey(),
        ], ['confidence' => $confidence, 'provider' => (string) $provider, 'reason' => "Brand detected from {$provider}"]);

        $business->setRelation('brand', $brand);
    }

    private function priority(string $provider): int
    {
        $index = array_search($provider, self::PROVIDER_PRIORITY, true);

        return $index === false ? count(self::PROVIDER_PRIORITY) : $index;
    }
}

<?php

namespace App\Identity\Brands;

use App\Enums\RelationshipType;
use App\Models\Brand;
use App\Models\EntityRelationship;
use App\Models\Organization;
use App\Support\Normalization\Domain;

/**
 * Enriches a brand with its canonical Wikidata identity: official website
 * (a corporate domain), aliases, countries and parent organization. Used
 * only for brands that already carry a Wikidata ID.
 *
 * Wikidata says what a brand is; it never proves a local branch is open.
 */
final class BrandEnricher
{
    /**
     * @param  list<string>  $enterpriseCategories  Registry categories whose brands are enterprises
     */
    public function __construct(
        private readonly WikidataClient $wikidata,
        private readonly BrandRegistry $registry,
        private readonly int $enterpriseMinEmployees,
        private readonly int $cacheDays,
        private readonly array $enterpriseCategories = [],
    ) {}

    public function needsEnrichment(Brand $brand): bool
    {
        $attempted = $brand->metadata['wikidata_attempted_at'] ?? null;

        return $brand->wikidata_id !== null
            && ($brand->wikidata_fetched_at === null || $brand->wikidata_fetched_at->lessThan(now()->subDays($this->cacheDays)))
            // A failed lookup is retried after a day, not on every evaluation.
            && ($attempted === null || now()->subDay()->greaterThan($attempted));
    }

    /**
     * @return bool Whether the brand was enriched
     */
    public function enrich(Brand $brand): bool
    {
        if ($brand->wikidata_id === null) {
            return false;
        }

        $brand->forceFill(['metadata' => [...($brand->metadata ?? []), 'wikidata_attempted_at' => now()->toIso8601String()]])->save();

        $entity = $this->wikidata->entity($brand->wikidata_id);

        if ($entity === null) {
            return false;
        }

        foreach ([$entity->label, $entity->label_ar, ...($entity->aliases ?? [])] as $alias) {
            $brand->addAlias($alias, 'wikidata');
        }

        $domain = Domain::registrable($entity->official_website);

        if ($domain !== null) {
            $brand->official_domains = array_values(array_unique([...($brand->official_domains ?? []), $domain]));
        }

        $brand->country_count = max($brand->country_count ?? 0, count($entity->country_ids ?? []));
        $brand->wikidata_fetched_at = now();
        $brand->scope = $this->registry->scopeFor($brand);

        $parentId = $entity->parent_ids[0] ?? null;
        $parent = $parentId !== null && $parentId !== $brand->wikidata_id ? $this->wikidata->entity($parentId) : null;

        if ($parent !== null && $parent->label !== null) {
            $organization = Organization::query()->updateOrCreate(['wikidata_id' => $parent->id], [
                'name' => $parent->label,
                'type' => 'company',
                'website' => $parent->official_website,
                'employees' => $parent->employees,
                'source' => 'wikidata',
            ]);

            $brand->organization_id = $organization->getKey();

            EntityRelationship::query()->updateOrCreate([
                'subject_type' => $brand->getMorphClass(), 'subject_id' => $brand->getKey(),
                'relationship' => RelationshipType::OwnedBy,
                'object_type' => $organization->getMorphClass(), 'object_id' => $organization->getKey(),
            ], ['confidence' => 85, 'provider' => 'wikidata', 'reason' => "Wikidata: {$entity->label} is owned by {$parent->label}"]);
        }

        // A very large company is an enterprise, not a shop chain.
        $employees = max($entity->employees ?? 0, $parent?->employees ?? 0);
        $brand->metadata = [...($brand->metadata ?? []), 'wikidata_employees' => $employees ?: null];

        $brand->save();

        if ($employees >= $this->enterpriseMinEmployees && $this->looksLikeEnterprise($brand)) {
            $this->registry->markEnterprise($brand);
        }

        return true;
    }

    /**
     * Restaurants and shops with many employees are still chains; only
     * registry categories such as banks or telecoms make an enterprise.
     */
    private function looksLikeEnterprise(Brand $brand): bool
    {
        return array_intersect($brand->categories ?? [], $this->enterpriseCategories) !== [];
    }
}

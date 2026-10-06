<?php

namespace App\Identity;

use App\Enums\EvidenceFact;
use App\Enums\RelationshipType;
use App\Identity\Brands\BrandIntelligenceService;
use App\Identity\Evidence\EvidenceReconciler;
use App\Identity\Evidence\WebsiteEvidenceExtractor;
use App\Models\Business;
use App\Models\EntityRelationship;
use App\Models\Organization;
use App\Models\WebsiteAudit;
use App\Sources\OpenAddresses\AddressContextService;
use App\Support\Normalization\Text;

/**
 * Works out who a business is:
 *
 *   link matching local places (store locators, Overture) → record website
 *   (first-party) evidence → address context → reconcile all evidence → detect the brand →
 *   classify the entity → store identity and graph edges.
 */
final class BusinessIdentityAnalyzer
{
    public function __construct(
        private readonly SourcePlaceLinker $linker,
        private readonly WebsiteEvidenceExtractor $websiteEvidence,
        private readonly EvidenceReconciler $reconciler,
        private readonly BrandIntelligenceService $brands,
        private readonly EntityClassifier $classifier,
        private readonly AddressContextService $addresses,
    ) {}

    public function analyze(Business $business, ?WebsiteAudit $audit): IdentityResult
    {
        $this->linker->linkBusiness($business);
        $this->websiteEvidence->sync($business, $audit);
        $this->addresses->enrich($business);

        $reconciliation = $this->reconciler->reconcile($business);
        $brand = $this->brands->assess($business, $reconciliation->evidence);
        $classification = $this->classifier->classify($business, $brand, $reconciliation);

        $business->forceFill([
            'entity_type' => $classification->type,
            'entity_confidence' => $classification->confidence,
            'entity_reason_codes' => $classification->codes(),
            'entity_reasons' => $classification->reasons,
            'classified_at' => now(),
        ])->save();

        $this->linkParentOrganization($business, $reconciliation->evidence->firstWhere('fact_type', EvidenceFact::ParentOrganization)?->value);

        return new IdentityResult($reconciliation, $brand, $classification);
    }

    /**
     * Business —parent_organization→ Organization, as declared by its website.
     */
    private function linkParentOrganization(Business $business, ?string $parentName): void
    {
        $edges = EntityRelationship::query()
            ->where('subject_type', $business->getMorphClass())
            ->where('subject_id', $business->getKey())
            ->where('relationship', RelationshipType::ParentOrganization);

        $name = Text::clean($parentName, 255);

        if ($name === null) {
            $edges->delete();

            return;
        }

        $organization = Organization::query()->where('name_normalized', Text::matchKey($name))->first()
            ?? Organization::query()->create(['name' => $name, 'type' => 'group', 'source' => 'website']);

        (clone $edges)->where('object_id', '!=', $organization->getKey())->delete();

        EntityRelationship::query()->updateOrCreate([
            'subject_type' => $business->getMorphClass(), 'subject_id' => $business->getKey(),
            'relationship' => RelationshipType::ParentOrganization,
            'object_type' => $organization->getMorphClass(), 'object_id' => $organization->getKey(),
        ], ['confidence' => 80, 'provider' => 'website', 'reason' => "The website names {$name} as its parent organization"]);
    }
}

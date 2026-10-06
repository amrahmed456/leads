<?php

namespace Tests\Feature\Intelligence;

use App\Enums\EntityType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\ReasonCode;
use App\Identity\EntityClassification;
use App\Identity\Evidence\ReconciliationResult;
use App\Intelligence\Eligibility\EligibilityInput;
use App\Intelligence\Eligibility\EligibilityResult;
use App\Intelligence\Eligibility\LeadEligibilityService;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Scoring\ScoreResult;
use App\Models\Business;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadEligibilityServiceTest extends TestCase
{
    public function test_an_independent_business_meeting_every_requirement_is_hot(): void
    {
        $result = $this->evaluate();

        $this->assertSame(LeadStatus::Hot, $result->status);
        $this->assertSame(ReasonCode::MeetsHotCriteria->value, $result->reasons[0]['code']);
        $this->assertContains('OPPORTUNITY_WEBSITE_MISSING', $result->codes());
        $this->assertSame(69, $result->score, 'round(60 × 0.45) + round(80 × 0.35) + round(70 × 0.20) = 27 + 28 + 14');
    }

    public function test_a_confidently_recognized_chain_is_disqualified_with_its_reasons(): void
    {
        $result = $this->evaluate(entity: $this->entity(EntityType::InternationalChain, 92, [
            ReasonCode::InternationalChain->reason('KFC operates in several countries'),
            ReasonCode::KnownBrand->reason('KFC confirmed by Overture, OpenStreetMap'),
        ]));

        $this->assertSame(LeadStatus::Disqualified, $result->status);
        $this->assertSame([ReasonCode::InternationalChain->value, ReasonCode::KnownBrand->value], $result->codes());
        $this->assertSame(0, $result->score);
    }

    public function test_an_uncertain_chain_classification_goes_to_review(): void
    {
        $result = $this->evaluate(entity: $this->entity(EntityType::NationalChain, 60, [ReasonCode::NationalChain->reason('…')]));

        $this->assertSame(LeadStatus::ReviewRequired, $result->status);
    }

    /**
     * @return array<string, array{0: EntityType}>
     */
    public static function hardDisqualifiers(): array
    {
        return [
            'government' => [EntityType::Government],
            'enterprise' => [EntityType::Enterprise],
            'aggregator' => [EntityType::Aggregator],
            'directory' => [EntityType::Directory],
            'corporate' => [EntityType::Corporate],
            'national chain' => [EntityType::NationalChain],
        ];
    }

    #[DataProvider('hardDisqualifiers')]
    public function test_hard_disqualifiers(EntityType $type): void
    {
        $this->assertSame(LeadStatus::Disqualified, $this->evaluate(entity: $this->entity($type, 90))->status);
    }

    public function test_a_franchise_goes_to_review_by_default(): void
    {
        $result = $this->evaluate(entity: $this->entity(EntityType::Franchise, 70, [ReasonCode::Franchise->reason('Operated by X under the Y brand')]));

        $this->assertSame(LeadStatus::ReviewRequired, $result->status);
        $this->assertContains(ReasonCode::Franchise->value, $result->codes());
    }

    public function test_the_franchise_treatment_is_configurable(): void
    {
        config(['intelligence.eligibility.franchise_treatment' => 'warm']);
        $this->assertSame(LeadStatus::Warm, $this->evaluate(entity: $this->entity(EntityType::Franchise, 70))->status, 'Capped at WARM, never HOT.');

        config(['intelligence.eligibility.franchise_treatment' => 'disqualify']);
        $this->assertSame(LeadStatus::Disqualified, $this->evaluate(entity: $this->entity(EntityType::Franchise, 70))->status);
    }

    public function test_an_unknown_entity_is_never_hot(): void
    {
        $this->assertSame(LeadStatus::ReviewRequired, $this->evaluate(entity: $this->entity(EntityType::Unknown, 30))->status);
        $this->assertSame(LeadStatus::Low, $this->evaluate(entity: $this->entity(EntityType::Unknown, 30), opportunity: 10)->status, 'Nothing worth reviewing.');
    }

    public function test_a_weak_chain_hint_sends_an_otherwise_hot_lead_to_review(): void
    {
        $result = $this->evaluate(entity: $this->entity(EntityType::LocalBusiness, 50, [
            ReasonCode::SingleLocation->reason('A single location'),
            [...ReasonCode::PossibleBrandMatch->reason('The name resembles the brand Kudu'), 'weak' => true],
        ]));

        $this->assertSame(LeadStatus::ReviewRequired, $result->status);
        $this->assertSame('Chain hint: The name resembles the brand Kudu', $result->reasons[0]['label']);
    }

    public function test_an_identity_conflict_sends_an_otherwise_hot_lead_to_review(): void
    {
        $result = $this->evaluate(evidence: $this->evidence(conflicts: [['fact' => 'name', 'severity' => 'major', 'values' => [], 'preferred' => 'X']]));

        $this->assertSame(LeadStatus::ReviewRequired, $result->status);
        $this->assertContains(ReasonCode::ConflictingIdentity->value, $result->codes());
    }

    public function test_one_independent_source_is_not_enough_for_hot(): void
    {
        $result = $this->evaluate(evidence: $this->evidence(groups: ['osm_family']));

        $this->assertSame(LeadStatus::Warm, $result->status);
        $this->assertContains(ReasonCode::InsufficientEvidence->value, $result->codes());
    }

    public function test_a_business_that_cannot_be_contacted_is_low(): void
    {
        $result = $this->evaluate(contactRoutes: []);

        $this->assertSame(LeadStatus::Low, $result->status);
        $this->assertContains(ReasonCode::NoContactRoute->value, $result->codes());
    }

    public function test_an_opportunity_we_do_not_sell_cannot_make_a_lead(): void
    {
        config(['intelligence.services_offered' => ['ecommerce']]);

        $result = $this->evaluate();

        $this->assertSame(LeadStatus::Low, $result->status);
        $this->assertContains(ReasonCode::NoSellableOpportunity->value, $result->codes());
    }

    public function test_an_excellent_digital_presence_with_nothing_to_sell_is_disqualified(): void
    {
        $opportunity = new ScoreResult(5, [['key' => 'website_strong', 'label' => '…', 'points' => 0], ['key' => 'strong_digital_presence', 'label' => '…', 'points' => -25]]);

        $result = $this->evaluate(opportunityScore: $opportunity);

        $this->assertSame(LeadStatus::Disqualified, $result->status);
        $this->assertSame([ReasonCode::StrongDigitalPresence->value], $result->codes());
    }

    public function test_a_permanently_closed_business_is_disqualified(): void
    {
        $result = $this->evaluate(business: Business::factory()->make(['operating_status' => 'permanently_closed']));

        $this->assertSame([ReasonCode::PermanentlyClosed->value], $result->codes());
    }

    public function test_weak_scores_make_a_low_lead_with_the_gaps_explained(): void
    {
        $result = $this->evaluate(confidence: 20, fit: 30);

        $this->assertSame(LeadStatus::Low, $result->status);
        $this->assertContains(ReasonCode::LowDataConfidence->value, $result->codes());
        $this->assertContains(ReasonCode::LowBusinessFit->value, $result->codes());
    }

    /**
     * @param  list<string>|null  $contactRoutes
     */
    private function evaluate(
        ?EntityClassification $entity = null,
        ?ReconciliationResult $evidence = null,
        int $confidence = 70,
        int $fit = 80,
        int $opportunity = 60,
        ?ScoreResult $opportunityScore = null,
        ?array $contactRoutes = null,
        ?Business $business = null,
    ): EligibilityResult {
        return $this->app->make(LeadEligibilityService::class)->evaluate(new EligibilityInput(
            business: $business ?? Business::factory()->make(),
            entity: $entity ?? $this->entity(EntityType::Independent, 75, [ReasonCode::OwnWebsite->reason('Has its own website')]),
            evidence: $evidence ?? $this->evidence(),
            dataConfidence: new ScoreResult($confidence, []),
            businessFit: new ScoreResult($fit, []),
            opportunity: $opportunityScore ?? new ScoreResult($opportunity, []),
            opportunities: [new DetectedOpportunity(OpportunityType::WebsiteMissing, OpportunityPriority::High, ['No website'], 'no_website')],
            contactRoutes: $contactRoutes ?? ['phone'],
        ));
    }

    /**
     * @param  list<array{code: string, label: string, weak?: bool}>  $reasons
     */
    private function entity(EntityType $type, int $confidence, array $reasons = []): EntityClassification
    {
        return new EntityClassification($type, $confidence, $reasons);
    }

    /**
     * @param  list<string>  $groups
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function evidence(array $groups = ['osm_family', 'overture'], array $conflicts = []): ReconciliationResult
    {
        return new ReconciliationResult(collect(), $groups, $conflicts, false, false, null, false);
    }
}

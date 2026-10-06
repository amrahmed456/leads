<?php

namespace Tests\Feature\Identity;

use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Enums\SourceRole;
use App\Identity\Evidence\EvidenceReconciler;
use App\Identity\Evidence\ReconciliationResult;
use App\Models\Business;
use App\Models\BusinessEvidence;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_groups_are_counted_once_and_context_sources_do_not_count(): void
    {
        $business = Business::factory()->create();
        $this->evidence($business, EvidenceFact::Existence, 'ABC Dental', 'openstreetmap', 'osm_family');
        $this->evidence($business, EvidenceFact::Existence, 'ABC Dental', 'geoapify', 'osm_family');
        $this->evidence($business, EvidenceFact::Existence, 'ABC Dental', 'overture', 'overture');
        $this->evidence($business, EvidenceFact::Address, 'Olaya St', 'openaddresses', 'address_data', SourceRole::AddressContext);

        $result = $this->reconcile($business);

        $this->assertEqualsCanonicalizing(['osm_family', 'overture'], $result->existenceGroups);
        $this->assertSame(2, $business->refresh()->independent_source_count);
        $this->assertSame(4, $business->evidence_count);
    }

    public function test_similar_names_from_different_sources_are_not_a_conflict(): void
    {
        $business = Business::factory()->create(['name' => 'ABC Dental']);
        $this->evidence($business, EvidenceFact::Name, 'ABC Dental', 'openstreetmap', 'osm_family');
        $this->evidence($business, EvidenceFact::Name, 'ABC Dental Group', 'website', 'first_party_web', SourceRole::Verification);
        $this->evidence($business, EvidenceFact::Name, 'ABC Dental - Riyadh Branch', 'alltheplaces', 'first_party', SourceRole::Verification);

        $result = $this->reconcile($business);

        $this->assertSame([], $result->conflicts);
        $this->assertFalse($business->refresh()->has_identity_conflict);
    }

    public function test_conflicting_values_are_all_kept_and_the_preferred_source_wins(): void
    {
        $business = Business::factory()->create(['website' => null]);
        $osm = $this->evidence($business, EvidenceFact::Website, 'https://abc-dental.sa', 'openstreetmap', 'osm_family');
        $overture = $this->evidence($business, EvidenceFact::Website, 'https://abcclinics.com', 'overture', 'overture');

        $result = $this->reconcile($business);

        $this->assertSame('website', $result->conflicts[0]['fact']);
        $this->assertSame('minor', $result->conflicts[0]['severity']);
        $this->assertSame('https://abcclinics.com', $result->conflicts[0]['preferred'], 'Overture ranks above OpenStreetMap in identity.source_priority.');
        $this->assertSame(EvidenceStatus::Active, $overture->refresh()->status);
        $this->assertSame(EvidenceStatus::Conflicting, $osm->refresh()->status, 'Kept with its provenance, not deleted.');
        $this->assertSame('https://abcclinics.com', $business->refresh()->website, 'A missing field is filled from the preferred value.');
    }

    public function test_entirely_different_names_from_two_confirming_sources_are_a_major_conflict(): void
    {
        $business = Business::factory()->create(['name' => 'Olaya Grill']);
        $this->evidence($business, EvidenceFact::Name, 'Olaya Grill', 'openstreetmap', 'osm_family');
        $this->evidence($business, EvidenceFact::Name, 'Hamad Auto Parts', 'overture', 'overture');

        $result = $this->reconcile($business);

        $this->assertTrue($result->hasMajorConflict());
        $this->assertTrue($business->refresh()->has_identity_conflict);
        $this->assertSame('Olaya Grill', $business->name, 'The name is never overwritten.');
    }

    public function test_evidence_not_seen_for_a_long_time_is_stale_and_stops_confirming(): void
    {
        $business = Business::factory()->create();
        $this->evidence($business, EvidenceFact::Existence, 'X', 'openstreetmap', 'osm_family', lastSeen: now()->subYears(2));

        $result = $this->reconcile($business);

        $this->assertSame([], $result->existenceGroups);
        $this->assertTrue($result->staleOnly);
        $this->assertSame(EvidenceStatus::Stale, BusinessEvidence::sole()->status);
    }

    public function test_the_same_phone_from_independent_sources_is_corroborated(): void
    {
        $business = Business::factory()->create();
        $this->evidence($business, EvidenceFact::Phone, '+966112003000', 'openstreetmap', 'osm_family');
        $this->evidence($business, EvidenceFact::Phone, '011 200 3000', 'website', 'first_party_web', SourceRole::Verification);

        $this->assertTrue($this->reconcile($business)->phoneCorroborated);
    }

    private function reconcile(Business $business): ReconciliationResult
    {
        return $this->app->make(EvidenceReconciler::class)->reconcile($business);
    }

    private function evidence(Business $business, EvidenceFact $fact, string $value, string $provider, string $group, SourceRole $role = SourceRole::Discovery, ?DateTimeInterface $lastSeen = null): BusinessEvidence
    {
        return BusinessEvidence::factory()->for($business)->fact($fact, $value)->from($provider, $group, $role)->create(['last_seen_at' => $lastSeen ?? now()]);
    }
}

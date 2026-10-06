<?php

namespace Tests\Feature\Intelligence;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Identity\Evidence\ReconciliationResult;
use App\Intelligence\Scoring\BusinessFitService;
use App\Intelligence\Scoring\DataConfidenceService;
use App\Intelligence\Scoring\ScoreResult;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataConfidenceAndFitTest extends TestCase
{
    use RefreshDatabase;

    public function test_confidence_grows_with_independent_groups_and_first_party_confirmation(): void
    {
        $business = Business::factory()->create(['last_verified_at' => now()]);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business]);

        $one = $this->confidence($business, ['osm_family'], null);
        $two = $this->confidence($business, ['osm_family', 'first_party_web'], $audit, phone: true);

        $this->assertSame(40, $one->score, '35 for one group + 5 recently seen.');
        $this->assertSame(85, $two->score, '60 + 10 first party + 5 phone + 5 recent + 5 working website.');
        $this->assertSame(['groups_2', 'first_party', 'phone_corroborated', 'recently_seen', 'website_working'], array_column($two->breakdown, 'key'));
    }

    public function test_conflicts_low_source_confidence_closure_and_junk_lower_confidence(): void
    {
        $business = Business::factory()->create(['name' => '12', 'operating_status' => 'permanently_closed', 'latitude' => null, 'longitude' => null]);

        $result = $this->app->make(DataConfidenceService::class)->score($business, new ReconciliationResult(
            collect(), ['osm_family', 'overture'],
            [['fact' => 'name', 'severity' => 'major', 'values' => [], 'preferred' => 'x'], ['fact' => 'website', 'severity' => 'minor', 'values' => [], 'preferred' => 'y']],
            false, false, 0.3, false,
        ), null);

        $this->assertSame(['groups_2', 'permanently_closed', 'source_confidence_low', 'major_conflict', 'minor_conflict', 'no_coordinates', 'junk_name'], array_column($result->breakdown, 'key'));
        $this->assertSame(0, $result->score);
    }

    public function test_business_fit_combines_the_category_with_the_kind_of_entity(): void
    {
        $fit = $this->app->make(BusinessFitService::class);

        $independent = Business::factory()->make(['category' => BusinessCategory::Dentist, 'entity_type' => EntityType::Independent]);
        $chain = Business::factory()->make(['category' => BusinessCategory::Restaurant, 'entity_type' => EntityType::InternationalChain]);
        $pharmacy = Business::factory()->make(['category' => BusinessCategory::Pharmacy, 'entity_type' => EntityType::LocalBusiness]);

        $this->assertSame(90, $fit->score($independent)->score);
        $this->assertSame(0, $fit->score($chain)->score, 'The international chain penalty.');
        $this->assertSame(40, $fit->score($pharmacy)->score);
        $this->assertSame('International chain: decisions are made centrally or elsewhere', $fit->score($chain)->breakdown[1]['label']);
    }

    /**
     * @param  list<string>  $groups
     */
    private function confidence(Business $business, array $groups, ?WebsiteAudit $audit, bool $phone = false): ScoreResult
    {
        return $this->app->make(DataConfidenceService::class)->score(
            $business,
            new ReconciliationResult(collect(), $groups, [], $phone, false, null, false),
            $audit,
        );
    }
}

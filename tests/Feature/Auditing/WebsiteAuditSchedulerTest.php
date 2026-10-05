<?php

namespace Tests\Feature\Auditing;

use App\Auditing\WebsiteAuditScheduler;
use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Enums\OpportunityType;
use App\Jobs\AuditBusinessWebsite;
use App\Models\Business;
use App\Models\SearchCampaign;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteAuditSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_an_audit_of_the_business_website(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => 'https://Alreef.sa/?utm_source=osm']);

        $audit = $this->scheduler()->queue($business);

        $this->assertSame(AuditStatus::Pending, $audit->status);
        $this->assertSame('https://Alreef.sa/?utm_source=osm', $audit->url);
        $this->assertSame('https://alreef.sa', $audit->normalized_url);
        $this->assertFalse($audit->forced);
        Queue::assertPushed(AuditBusinessWebsite::class, fn (AuditBusinessWebsite $job) => $job->audit->is($audit));
    }

    public function test_does_not_queue_a_second_audit_while_one_is_queued_or_running(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => 'https://alreef.sa']);
        WebsiteAudit::factory()->pending()->create(['business_id' => $business, 'url' => 'https://alreef.sa', 'normalized_url' => 'https://alreef.sa']);

        $this->assertNull($this->scheduler()->queue($business));
        $this->assertNull($this->scheduler()->queue($business, force: true), 'Even a manual re-audit waits for the active one.');

        Queue::assertNotPushed(AuditBusinessWebsite::class);
        $this->assertSame(1, $business->websiteAudits()->count());
    }

    public function test_a_recent_successful_audit_is_not_repeated(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $fresh = $this->businessWithAudit(['audited_at' => now()->subDays(5)]);
        $stale = $this->businessWithAudit(['audited_at' => now()->subDays(31)]);

        $this->assertNull($this->scheduler()->queue($fresh));
        $this->assertNotNull($this->scheduler()->queue($stale));
        Queue::assertPushedTimes(AuditBusinessWebsite::class, 1);
    }

    public function test_a_failed_audit_is_retried_only_after_a_delay(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $recentFailure = $this->businessWithAudit(['audited_at' => now()->subHours(2)], failed: true);
        $oldFailure = $this->businessWithAudit(['audited_at' => now()->subHours(25)], failed: true);

        $this->assertNull($this->scheduler()->queue($recentFailure));
        $this->assertNotNull($this->scheduler()->queue($oldFailure));
    }

    public function test_a_manual_re_audit_ignores_freshness(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = $this->businessWithAudit(['audited_at' => now()->subDay()]);

        $audit = $this->scheduler()->queue($business, force: true);

        $this->assertTrue($audit->forced);
        Queue::assertPushed(AuditBusinessWebsite::class);
    }

    public function test_a_changed_website_is_audited_again(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = $this->businessWithAudit(['audited_at' => now()->subDay()]);
        $business->update(['website' => 'https://new-alreef.sa']);

        $this->assertSame('https://new-alreef.sa', $this->scheduler()->queue($business)->normalized_url);
    }

    public function test_a_business_without_a_website_is_marked_not_applicable_once(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => null]);

        $this->assertNull($this->scheduler()->queue($business));
        $this->assertNull($this->scheduler()->queue($business));

        $this->assertSame([AuditStatus::NotApplicable], $business->websiteAudits()->pluck('status')->all());
        Queue::assertNotPushed(AuditBusinessWebsite::class);
    }

    public function test_a_campaign_batch_spreads_audits_out_and_evaluates_businesses_without_a_website(): void
    {
        $this->freezeTime();
        config(['auditing.per_minute' => 20]);
        Queue::fake([AuditBusinessWebsite::class]);
        $campaign = SearchCampaign::factory()->create();
        $campaign->businesses()->attach([
            Business::factory()->create(['name' => 'A', 'website' => 'https://a.example.sa'])->id,
            Business::factory()->create(['name' => 'B', 'website' => 'https://b.example.sa'])->id,
            $noWebsite = Business::factory()->create(['name' => 'C', 'website' => null, 'phone' => '+966501234567'])->id,
        ]);

        $summary = $this->scheduler()->queueMany($campaign->businesses());

        $this->assertSame(['queued' => 2, 'without_website' => 1, 'skipped' => 0, 'failed' => 0], $summary);
        Queue::assertPushed(AuditBusinessWebsite::class, fn (AuditBusinessWebsite $job) => $job->audit->normalized_url === 'https://a.example.sa' && $job->delay === null);
        Queue::assertPushed(AuditBusinessWebsite::class, fn (AuditBusinessWebsite $job) => $job->audit->normalized_url === 'https://b.example.sa' && now()->addSeconds(3)->equalTo($job->delay));
        $this->assertSame([OpportunityType::WebsiteMissing], Business::find($noWebsite)->opportunities()->pluck('type')->all());
        $this->assertSame(3, $campaign->businesses()->has('leadScore')->count(), 'Every business gets a first lead score.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function businessWithAudit(array $attributes, bool $failed = false): Business
    {
        $business = Business::factory()->create(['website' => 'https://alreef.sa']);
        $factory = WebsiteAudit::factory();

        ($failed ? $factory->failed(AuditFailure::Timeout) : $factory)->create([
            'business_id' => $business,
            'url' => 'https://alreef.sa',
            'normalized_url' => 'https://alreef.sa',
            ...$attributes,
        ]);

        return $business;
    }

    private function scheduler(): WebsiteAuditScheduler
    {
        return $this->app->make(WebsiteAuditScheduler::class);
    }
}

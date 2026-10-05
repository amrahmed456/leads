<?php

namespace Tests\Feature\Console;

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

class AuditCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_audits_for_a_campaign_and_evaluates_businesses_without_a_website(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $campaign = SearchCampaign::factory()->create();
        $withWebsite = Business::factory()->create(['website' => 'https://alreef.sa']);
        $withoutWebsite = Business::factory()->create(['website' => null]);
        $campaign->businesses()->attach([$withWebsite->id, $withoutWebsite->id]);

        $this->artisan('audits:queue', ['--campaign' => $campaign->id])
            ->expectsTable(['Queued', 'Without website', 'Skipped (recent or in progress)', 'Failed'], [[1, 1, 0, 0]])
            ->assertSuccessful();

        Queue::assertPushedTimes(AuditBusinessWebsite::class, 1);
        $this->assertSame([OpportunityType::WebsiteMissing], $withoutWebsite->opportunities()->pluck('type')->all());
    }

    public function test_requires_choosing_which_businesses_to_audit(): void
    {
        $this->artisan('audits:queue')->assertFailed();
        $this->artisan('audits:queue', ['--campaign' => 999])->assertFailed();
    }

    public function test_marks_stuck_audits_as_failed(): void
    {
        $this->freezeTime();
        $stuck = WebsiteAudit::factory()->pending()->create(['status' => AuditStatus::Running, 'started_at' => now()->subHour()]);
        $neverProcessed = WebsiteAudit::factory()->pending()->create(['queued_at' => now()->subDays(3)]);
        $active = WebsiteAudit::factory()->pending()->create(['status' => AuditStatus::Running, 'started_at' => now()->subMinutes(5)]);

        $this->artisan('audits:fail-stale')->assertSuccessful();

        $this->assertSame(AuditFailure::Unexpected, $stuck->fresh()->failure_type);
        $this->assertSame(AuditStatus::Failed, $neverProcessed->fresh()->status);
        $this->assertSame(AuditStatus::Running, $active->fresh()->status);
    }
}

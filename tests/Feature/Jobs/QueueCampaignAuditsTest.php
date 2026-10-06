<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AuditBusinessWebsite;
use App\Jobs\QueueCampaignAudits;
use App\Jobs\RecalculateLeadEligibility;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueCampaignAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_are_queued_and_qualification_is_split_into_chunked_jobs(): void
    {
        Queue::fake([AuditBusinessWebsite::class, RecalculateLeadEligibility::class]);
        $campaign = SearchCampaign::factory()->create();
        $withWebsite = Business::factory()->create(['website' => 'https://olaya.sa']);
        $campaign->businesses()->attach([$withWebsite->id, ...Business::factory()->count(150)->create(['website' => null])->modelKeys()]);

        QueueCampaignAudits::dispatchSync($campaign);

        Queue::assertPushed(AuditBusinessWebsite::class, 1);
        Queue::assertPushed(RecalculateLeadEligibility::class, 2);
        Queue::assertPushed(RecalculateLeadEligibility::class, fn (RecalculateLeadEligibility $job) => count($job->businessIds) === 100);
        $this->assertSame(0, LeadScore::count(), 'Nothing is evaluated inside this job.');
    }
}

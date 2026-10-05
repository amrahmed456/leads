<?php

namespace Tests\Feature\Console;

use App\Enums\CampaignStatus;
use App\Jobs\QueueCampaignAudits;
use App\Models\Business;
use App\Models\SearchCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_can_be_run_from_the_command_line(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake(['https://overpass.test/*' => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);
        $campaign = SearchCampaign::factory()->create();

        $this->artisan('campaigns:discover', ['campaign' => $campaign->id, '--sync' => true])
            ->expectsOutputToContain('Running discovery')
            ->assertSuccessful();

        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
        $this->assertSame(4, Business::count());
    }

    public function test_the_command_reports_failures(): void
    {
        Http::fake(['https://overpass.test/*' => Http::response('down', 500)]);
        $campaign = SearchCampaign::factory()->create();

        $this->artisan('campaigns:discover', ['campaign' => $campaign->id, '--sync' => true])
            ->expectsOutputToContain('Discovery failed')
            ->assertFailed();

        $this->assertSame(CampaignStatus::Failed, $campaign->refresh()->status);
    }

    public function test_stale_running_campaigns_are_marked_failed(): void
    {
        $stale = SearchCampaign::factory()->status(CampaignStatus::Running)->create(['started_at' => now()->subHours(2)]);
        $active = SearchCampaign::factory()->status(CampaignStatus::Running)->create(['started_at' => now()->subMinutes(2)]);

        $this->artisan('campaigns:fail-stale')->assertSuccessful();

        $this->assertSame(CampaignStatus::Failed, $stale->refresh()->status);
        $this->assertSame(CampaignStatus::Running, $active->refresh()->status);
    }
}

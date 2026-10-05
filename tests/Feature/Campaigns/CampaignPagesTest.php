<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\RunCampaignDiscovery;
use App\Livewire\Campaigns\Show;
use App\Models\Business;
use App\Models\SearchCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_require_authentication(): void
    {
        $campaign = SearchCampaign::factory()->create();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('campaigns.index'))->assertRedirect(route('login'));
        $this->get(route('campaigns.show', $campaign))->assertRedirect(route('login'));
    }

    public function test_the_dashboard_shows_totals_and_recent_activity(): void
    {
        $campaign = SearchCampaign::factory()->status(CampaignStatus::Completed)->create(['name' => 'Jeddah Cafés']);
        $business = Business::factory()->withSource()->create(['name' => 'Brew Lab', 'website' => null]);
        $campaign->businesses()->attach($business);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Businesses discovered', '1', 'Search campaigns', '1'])
            ->assertSee('Jeddah Cafés')
            ->assertSee('Brew Lab')
            ->assertSee('No website');
    }

    public function test_the_campaign_list_and_details_render(): void
    {
        $campaign = SearchCampaign::factory()->status(CampaignStatus::Failed)->create(['name' => 'Dubai Gyms']);
        $campaign->forceFill(['error_message' => 'The Overpass API is busy'])->save();
        $campaign->businesses()->attach(Business::factory()->withSource()->create(['name' => 'Iron Temple']));

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('campaigns.index'))->assertOk()->assertSee('Dubai Gyms');

        $this->actingAs($user)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Discovery failed')
            ->assertSee('The Overpass API is busy')
            ->assertSee('Iron Temple')
            ->assertSee('Retry discovery');
    }

    public function test_discovery_can_be_run_again_for_a_finished_campaign(): void
    {
        Queue::fake();
        $campaign = SearchCampaign::factory()->status(CampaignStatus::Failed)->create();

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['campaign' => $campaign])
            ->call('startDiscovery')
            ->assertRedirect(route('campaigns.show', $campaign));

        $this->assertSame(CampaignStatus::Pending, $campaign->refresh()->status);
        Queue::assertPushed(RunCampaignDiscovery::class);
    }

    public function test_discovery_cannot_be_started_while_a_run_is_in_progress(): void
    {
        Queue::fake();
        $campaign = SearchCampaign::factory()->status(CampaignStatus::Running)->create();

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['campaign' => $campaign])
            ->assertDontSee('Run discovery again')
            ->call('startDiscovery')
            ->assertForbidden();

        $this->assertSame(CampaignStatus::Running, $campaign->refresh()->status);
        Queue::assertNothingPushed();
    }
}

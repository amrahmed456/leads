<?php

namespace Tests\Feature\Campaigns;

use App\Enums\BusinessCategory;
use App\Enums\CampaignStatus;
use App\Enums\Country;
use App\Jobs\RunCampaignDiscovery;
use App\Livewire\Campaigns\Create;
use App\Models\SearchCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CreateSearchCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_form_is_only_available_to_signed_in_users(): void
    {
        $this->get(route('campaigns.create'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('New search campaign');
    }

    public function test_creating_a_campaign_stores_it_and_queues_discovery(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Create::class)
            ->set('name', 'Riyadh Restaurants')
            ->set('country', 'SA')
            ->set('city', '  Riyadh ')
            ->set('category', 'restaurant')
            ->set('keywords', 'مطعم, restaurant، cafe, Restaurant')
            ->set('radiusKm', '7.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('campaigns.show', SearchCampaign::sole()));

        $campaign = SearchCampaign::sole();
        $this->assertSame('Riyadh Restaurants', $campaign->name);
        $this->assertSame('openstreetmap', $campaign->provider);
        $this->assertSame(Country::SaudiArabia, $campaign->country_code);
        $this->assertSame('Riyadh', $campaign->city);
        $this->assertSame(BusinessCategory::Restaurant, $campaign->category);
        $this->assertSame(['مطعم', 'restaurant', 'cafe'], $campaign->keywords);
        $this->assertSame(7500, $campaign->radius_meters);
        $this->assertNull($campaign->latitude, 'Coordinates are resolved by the job.');
        $this->assertSame(CampaignStatus::Pending, $campaign->status);

        Queue::assertPushed(RunCampaignDiscovery::class, fn (RunCampaignDiscovery $job) => $job->campaign->is($campaign));
    }

    public function test_the_name_defaults_to_category_and_city_and_coordinates_are_optional(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Create::class)
            ->set('country', 'AE')
            ->set('city', 'Dubai')
            ->set('category', 'dentist')
            ->set('latitude', '25.2048')
            ->set('longitude', '55.2708')
            ->call('save')
            ->assertHasNoErrors();

        $campaign = SearchCampaign::sole();
        $this->assertSame('Dentists in Dubai', $campaign->name);
        $this->assertSame(25.2048, $campaign->latitude);
        $this->assertSame(55.2708, $campaign->longitude);
        $this->assertNull($campaign->keywords);
    }

    public function test_invalid_input_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Create::class)
            ->set('country', 'EG')
            ->set('city', '')
            ->set('category', 'spaceships')
            ->set('provider', 'google_places')
            ->set('keywords', 'pizza|.*')
            ->set('latitude', '95')
            ->set('radiusKm', '500')
            ->call('save')
            ->assertHasErrors([
                'country', 'city', 'category', 'provider', 'keywords',
                'latitude' => 'between', 'longitude' => 'required_with', 'radiusKm' => 'max',
            ]);

        $this->assertSame(0, SearchCampaign::count());
        Queue::assertNothingPushed();
    }

    public function test_campaign_status_and_statistics_cannot_be_mass_assigned(): void
    {
        $campaign = new SearchCampaign(['name' => 'X']);

        $this->expectException(MassAssignmentException::class);

        $campaign->fill(['status' => 'completed', 'results_count' => 999]);
    }
}

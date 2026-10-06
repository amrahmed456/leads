<?php

namespace Tests\Feature\Sources;

use App\Enums\SourceHealth;
use App\Enums\SourceRole;
use App\Models\DataSource;
use App\Sources\QuotaExceededException;
use App\Sources\QuotaManager;
use App\Sources\SecretScrubber;
use App\Sources\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_source_declares_its_metadata(): void
    {
        foreach ($this->registry()->all() as $key => $source) {
            $this->assertNotSame('', $source->label, $key);
            $this->assertNotSame([], $source->roles, $key);
            $this->assertNotSame('', $source->independenceGroup, $key);
            $this->assertNotSame('', $source->coverage, $key);
            $this->assertContains($source->tier, ['free', 'paid'], $key);
        }

        $geoapify = $this->registry()->descriptor('geoapify');
        $this->assertTrue($geoapify->hasRole(SourceRole::Discovery));
        $this->assertSame(['api_key' => 'API key'], $geoapify->credentials);
        $this->assertSame('credits', $geoapify->quotaUnit());
    }

    public function test_sources_sharing_a_dataset_share_an_independence_group(): void
    {
        $registry = $this->registry();

        $this->assertSame($registry->independenceGroup('openstreetmap'), $registry->independenceGroup('geoapify'));
        $this->assertNotSame($registry->independenceGroup('openstreetmap'), $registry->independenceGroup('overture'));
        $this->assertNotSame($registry->independenceGroup('overture'), $registry->independenceGroup('alltheplaces'));
        $this->assertSame($registry->independenceGroup('nsi'), $registry->independenceGroup('wikidata'));
    }

    public function test_free_sources_are_available_by_default_and_paid_or_future_ones_never_are(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->isAvailable('openstreetmap'));
        $this->assertTrue($registry->isAvailable('overture'));
        $this->assertFalse($registry->isAvailable('geoapify'), 'Needs an API key.');
        $this->assertFalse($registry->isAvailable('google_places'));

        $registry->setEnabled('google_places', true);
        $this->assertFalse($registry->isAvailable('google_places'), 'Not implemented: can never be enabled.');
        $this->assertSame(SourceHealth::NotImplemented, $registry->health('google_places'));
    }

    public function test_credentials_are_encrypted_at_rest_and_masked_for_display(): void
    {
        $registry = $this->registry();

        $registry->saveCredentials('geoapify', ['api_key' => ' secret-key-9876 ', 'unknown' => 'ignored']);

        $raw = (string) DB::table('data_sources')->where('key', 'geoapify')->value('credentials');
        $this->assertStringNotContainsString('secret-key-9876', $raw);
        $this->assertSame('secret-key-9876', $registry->credential('geoapify', 'api_key'));
        $this->assertTrue($registry->isAvailable('geoapify'));
        $this->assertSame('••••••9876', SecretScrubber::mask($registry->credential('geoapify', 'api_key')));
        $this->assertArrayNotHasKey('credentials', DataSource::where('key', 'geoapify')->sole()->toArray());

        $registry->saveCredentials('geoapify', ['api_key' => '']);
        $this->assertSame('secret-key-9876', $registry->credential('geoapify', 'api_key'), 'An empty field keeps the stored value.');
    }

    public function test_failures_are_recorded_without_secrets_and_change_health(): void
    {
        $registry = $this->registry();
        $registry->saveCredentials('geoapify', ['api_key' => 'secret-key-9876']);

        $registry->recordFailure('geoapify', 'cURL error for https://api.geoapify.com/v2/places?apiKey=secret-key-9876&limit=20');

        $this->assertSame('cURL error for https://api.geoapify.com/v2/places?apiKey=[redacted]&limit=20', $registry->state('geoapify')->last_error);
        $this->assertSame(SourceHealth::Failing, $registry->health('geoapify'));

        $registry->recordSuccess('geoapify');
        $this->assertSame(SourceHealth::Degraded, $registry->health('geoapify'), 'Recovered, but failed in the last day.');
    }

    public function test_quota_consumption_never_exceeds_the_daily_budget(): void
    {
        $quotas = $this->app->make(QuotaManager::class);

        $quotas->consume('geoapify', 2990);
        $quotas->consume('geoapify', 10);
        $this->assertSame(0, $quotas->remaining('geoapify'));

        try {
            $quotas->consume('geoapify');
            $this->fail('The budget is used up.');
        } catch (QuotaExceededException $e) {
            $this->assertSame(3000, $e->limit);
        }

        $this->assertTrue($quotas->isExhausted('geoapify'));
        $this->assertSame(2, $quotas->usage('geoapify')->requests);
    }

    public function test_the_admin_can_override_the_daily_budget_and_it_resets_the_next_day(): void
    {
        $state = $this->registry()->state('wikidata');
        $state->settings = ['daily_quota' => 1];
        $state->save();
        $quotas = $this->app->make(QuotaManager::class);

        $quotas->consume('wikidata');
        $this->assertTrue($quotas->isExhausted('wikidata'));

        $this->travel(1)->days();
        $this->assertFalse($quotas->isExhausted('wikidata'));
        $quotas->consume('wikidata');
        $this->assertSame(1, $quotas->used('wikidata'));
    }

    public function test_unlimited_sources_still_track_usage(): void
    {
        $quotas = $this->app->make(QuotaManager::class);

        $quotas->consume('openstreetmap');
        $quotas->adjust('openstreetmap', -5);

        $this->assertNull($quotas->remaining('openstreetmap'));
        $this->assertSame(0, $quotas->used('openstreetmap'), 'Adjustments never go below zero.');
    }

    private function registry(): SourceRegistry
    {
        return $this->app->make(SourceRegistry::class);
    }
}

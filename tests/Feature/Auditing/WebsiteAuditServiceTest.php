<?php

namespace Tests\Feature\Auditing;

use App\Auditing\Exceptions\WebsiteFetchException;
use App\Auditing\WebsiteAuditService;
use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Enums\BusinessCategory;
use App\Enums\WebsiteSignal;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_the_homepage_and_stores_the_findings_but_not_the_page(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        Http::fake(['https://alreef.sa' => Http::response($this->websiteFixture('arabic-restaurant.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $audit = $this->pendingAudit('https://alreef.sa', BusinessCategory::Restaurant);

        $this->service()->run($audit);

        $audit->refresh();
        $this->assertSame(AuditStatus::Completed, $audit->status);
        $this->assertSame('https://alreef.sa', $audit->normalized_url);
        $this->assertSame('https://alreef.sa', $audit->final_url);
        $this->assertSame(200, $audit->http_status);
        $this->assertTrue($audit->reachable);
        $this->assertTrue($audit->is_https);
        $this->assertTrue($audit->ssl_valid);
        $this->assertSame(1, $audit->pages_checked);
        $this->assertSame(1, $audit->attempts);
        $this->assertSame('WordPress', $audit->cms);
        $this->assertTrue($audit->has_whatsapp);
        $this->assertTrue($audit->has_phone);
        $this->assertFalse($audit->has_online_ordering);
        $this->assertTrue($audit->has_arabic);
        $this->assertFalse($audit->has_english);
        $this->assertSame(55, $audit->score);
        $this->assertSame(['Talabat', 'HungerStation'], $audit->signalData(WebsiteSignal::DeliveryApps)['platforms']);
        $this->assertNotNull($audit->audited_at);
        $this->assertStringNotContainsString('<html', json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_website_that_blocks_the_request_fails_without_counting_as_broken(): void
    {
        Http::fake(['https://blocked.sa' => Http::response('Forbidden', 403)]);
        $audit = $this->pendingAudit('https://blocked.sa');

        $this->service()->run($audit);

        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertSame(AuditFailure::AccessDenied, $audit->failure_type);
        $this->assertSame(403, $audit->http_status);
        $this->assertTrue($audit->reachable);
        $this->assertFalse($audit->showsBrokenWebsite());
        $this->assertNull($audit->has_booking, 'Nothing was analyzed, so features are unknown.');
    }

    public function test_a_missing_homepage_counts_as_a_broken_website(): void
    {
        Http::fake(['https://gone.sa' => Http::response('Not Found', 404)]);
        $audit = $this->pendingAudit('https://gone.sa');

        $this->service()->run($audit);

        $this->assertSame(AuditFailure::NotFound, $audit->failure_type);
        $this->assertTrue($audit->showsBrokenWebsite());
    }

    public function test_a_server_error_is_left_to_the_job_to_retry(): void
    {
        Http::fake(['https://busy.sa' => Http::response('Unavailable', 503)]);
        $audit = $this->pendingAudit('https://busy.sa');

        try {
            $this->service()->run($audit);
            $this->fail('Expected a retryable failure.');
        } catch (WebsiteFetchException $e) {
            $this->assertSame(AuditFailure::ServerError, $e->failure);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_a_non_html_response_cannot_be_analyzed(): void
    {
        Http::fake(['https://menu.sa' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf'])]);
        $audit = $this->pendingAudit('https://menu.sa');

        $this->service()->run($audit);

        $this->assertSame(AuditFailure::NotHtml, $audit->failure_type);
    }

    public function test_an_invalid_website_address_fails_permanently(): void
    {
        $audit = $this->pendingAudit('javascript:alert(1)');

        $this->service()->run($audit);

        $this->assertSame(AuditFailure::InvalidUrl, $audit->failure_type);
        Http::assertNothingSent();
    }

    public function test_reuses_a_recent_audit_of_the_same_website_instead_of_fetching_it_again(): void
    {
        $original = WebsiteAudit::factory()->create([
            'business_id' => Business::factory()->create(['website' => 'https://kudu.com.sa', 'category' => BusinessCategory::Restaurant]),
            'url' => 'https://kudu.com.sa', 'normalized_url' => 'https://kudu.com.sa',
            'has_whatsapp' => true, 'score' => 81, 'audited_at' => now()->subDays(3),
        ]);
        $branch = $this->pendingAudit('https://kudu.com.sa', BusinessCategory::Restaurant);

        $this->service()->run($branch);

        Http::assertNothingSent();
        $this->assertSame(AuditStatus::Completed, $branch->status);
        $this->assertSame($original->id, $branch->reused_audit_id);
        $this->assertTrue($branch->has_whatsapp);
        $this->assertSame(81, $branch->score);
        $this->assertSame(0, $branch->pages_checked);
    }

    public function test_a_manual_re_audit_always_fetches_the_website(): void
    {
        WebsiteAudit::factory()->create([
            'business_id' => Business::factory()->create(['website' => 'https://kudu.com.sa', 'category' => BusinessCategory::Restaurant]),
            'url' => 'https://kudu.com.sa', 'normalized_url' => 'https://kudu.com.sa', 'audited_at' => now()->subDay(),
        ]);
        Http::fake(['https://kudu.com.sa' => Http::response('<html><title>Kudu</title></html>', 200, ['Content-Type' => 'text/html'])]);
        $audit = $this->pendingAudit('https://kudu.com.sa', BusinessCategory::Restaurant);
        $audit->forceFill(['forced' => true])->save();

        $this->service()->run($audit);

        Http::assertSentCount(1);
        $this->assertNull($audit->reused_audit_id);
    }

    private function pendingAudit(string $website, BusinessCategory $category = BusinessCategory::Dentist): WebsiteAudit
    {
        return WebsiteAudit::factory()->pending()->create([
            'business_id' => Business::factory()->create(['website' => $website, 'category' => $category]),
            'url' => $website,
            'normalized_url' => $website,
        ]);
    }

    private function service(): WebsiteAuditService
    {
        return $this->app->make(WebsiteAuditService::class);
    }

    private function websiteFixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/websites/{$name}"));
    }
}

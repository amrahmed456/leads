<?php

namespace Tests\Feature\Jobs;

use App\Auditing\WebsiteAuditService;
use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Enums\BusinessCategory;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\BusinessEvaluator;
use App\Jobs\AuditBusinessWebsite;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AuditBusinessWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_audit_updates_the_opportunities_and_lead_score(): void
    {
        Http::fake(['https://alreef.sa' => Http::response(
            (string) file_get_contents(base_path('tests/Fixtures/websites/arabic-restaurant.html')), 200, ['Content-Type' => 'text/html'],
        )]);
        $audit = $this->pendingAudit('https://alreef.sa/', BusinessCategory::Restaurant);

        AuditBusinessWebsite::dispatch($audit);

        $business = $audit->business->fresh(['opportunities', 'leadScore']);
        $this->assertSame(AuditStatus::Completed, $audit->fresh()->status);
        $ordering = $business->opportunities->firstWhere('type', OpportunityType::OnlineOrdering);
        $this->assertSame(OpportunityPriority::High, $ordering->priority);
        $this->assertContains('Sells through Talabat, HungerStation; direct ordering would avoid marketplace commissions', $ordering->reasons);
        $this->assertSame($audit->id, $ordering->website_audit_id);
        $this->assertNotNull($business->leadScore);
    }

    public function test_a_temporary_failure_is_released_to_retry_later(): void
    {
        Http::fake(['https://busy.sa' => Http::response('Unavailable', 503)]);
        $audit = $this->pendingAudit('https://busy.sa/');
        $job = (new AuditBusinessWebsite($audit))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertReleased(delay: 300);
        $audit->refresh();
        $this->assertSame(AuditStatus::Pending, $audit->status);
        $this->assertSame(AuditFailure::ServerError, $audit->failure_type);
        $this->assertStringStartsWith('Attempt 1 failed:', $audit->error_message);
    }

    public function test_a_retry_after_header_sets_the_retry_delay(): void
    {
        Http::fake(['https://busy.sa' => Http::response('Slow down', 429, ['Retry-After' => '120'])]);
        $job = (new AuditBusinessWebsite($this->pendingAudit('https://busy.sa/')))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertReleased(delay: 120);
    }

    public function test_the_last_attempt_records_the_failure_and_evaluates_the_business(): void
    {
        Http::fake(['https://busy.sa' => Http::response('Unavailable', 503)]);
        $audit = $this->pendingAudit('https://busy.sa/');
        $audit->forceFill(['attempts' => 2])->save();
        $job = (new AuditBusinessWebsite($audit))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertNotReleased();
        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
        $this->assertSame(503, $audit->fresh()->http_status);
        $this->assertSame([OpportunityType::WebsiteRedesign], $audit->business->opportunities()->pluck('type')->all());
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        Http::fake(['https://blocked.sa' => Http::response('Forbidden', 403)]);
        $audit = $this->pendingAudit('https://blocked.sa/');
        $job = (new AuditBusinessWebsite($audit))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(AuditFailure::AccessDenied, $audit->fresh()->failure_type);
    }

    public function test_an_audit_that_already_finished_is_not_run_again(): void
    {
        $audit = WebsiteAudit::factory()->create();

        $this->handle((new AuditBusinessWebsite($audit))->withFakeQueueInteractions());

        Http::assertNothingSent();
        $this->assertSame(1, $audit->fresh()->attempts);
    }

    public function test_an_unexpected_crash_fails_the_audit_and_is_logged(): void
    {
        Event::fake([MessageLogged::class]);
        $audit = $this->pendingAudit('https://alreef.sa/');

        (new AuditBusinessWebsite($audit))->failed(new RuntimeException('Database went away'));

        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
        $this->assertSame(AuditFailure::Unexpected, $audit->fresh()->failure_type);
        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->level === 'error'
            && $log->context['audit_id'] === $audit->id
            && $log->context['message'] === 'Database went away');
    }

    private function handle(AuditBusinessWebsite $job): void
    {
        $job->handle($this->app->make(WebsiteAuditService::class), $this->app->make(BusinessEvaluator::class));
    }

    private function pendingAudit(string $website, BusinessCategory $category = BusinessCategory::Dentist): WebsiteAudit
    {
        return WebsiteAudit::factory()->pending()->create([
            'business_id' => Business::factory()->create(['website' => $website, 'category' => $category]),
            'url' => $website,
            'normalized_url' => rtrim($website, '/'),
        ]);
    }
}

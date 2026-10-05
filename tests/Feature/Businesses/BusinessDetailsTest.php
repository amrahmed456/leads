<?php

namespace Tests\Feature\Businesses;

use App\Enums\AuditStatus;
use App\Enums\BusinessCategory;
use App\Intelligence\BusinessEvaluator;
use App\Jobs\AuditBusinessWebsite;
use App\Livewire\Businesses\Show;
use App\Models\Business;
use App\Models\User;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_digital_presence_opportunities_and_lead_score_of_an_audited_business(): void
    {
        $business = Business::factory()->create(['name' => 'Smile Dental', 'website' => 'https://smile.ae', 'category' => BusinessCategory::Dentist]);
        WebsiteAudit::factory()->create([
            'business_id' => $business, 'url' => 'https://smile.ae', 'normalized_url' => 'https://smile.ae',
            'has_whatsapp' => true, 'cms' => 'WordPress', 'score' => 58,
            'score_breakdown' => ['checks' => [
                ['key' => 'whatsapp', 'label' => 'WhatsApp contact', 'points' => 10, 'max' => 10, 'passed' => true],
                ['key' => 'online_booking', 'label' => 'No online booking', 'points' => 0, 'max' => 10, 'passed' => false],
            ]],
        ]);
        $this->app->make(BusinessEvaluator::class)->evaluate($business);

        $this->actingAs(User::factory()->create())
            ->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSeeInOrder(['Opportunities', 'Online booking', 'No online booking found on the website'])
            ->assertSeeInOrder(['Digital presence', 'https://smile.ae', 'Reachable', 'HTTPS', 'WhatsApp', 'WordPress'])
            ->assertSeeInOrder(['Audit score', '58', 'Positive signals', 'WhatsApp contact', 'Negative signals', 'No online booking'])
            ->assertSee('Lead score')
            ->assertSee('Re-audit website');
    }

    public function test_shows_website_development_for_a_business_without_a_website(): void
    {
        $business = Business::factory()->create(['website' => null, 'phone' => '+966501234567']);
        $this->app->make(BusinessEvaluator::class)->evaluate($business);

        $this->actingAs(User::factory()->create())
            ->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSeeInOrder(['Website development', 'Business has no website', 'Contact information available (phone)'])
            ->assertSeeInOrder(['Digital presence', 'Website', 'Not found'])
            ->assertSee('No website');
    }

    public function test_re_audit_queues_a_fresh_audit_of_the_website(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => 'https://smile.ae']);
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://smile.ae', 'normalized_url' => 'https://smile.ae']);

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['business' => $business])
            ->call('analyze')
            ->assertRedirect(route('businesses.show', $business));

        $audit = $business->websiteAudits()->latest('id')->first();
        $this->assertSame(AuditStatus::Pending, $audit->status);
        $this->assertTrue($audit->forced);
        Queue::assertPushed(AuditBusinessWebsite::class, fn (AuditBusinessWebsite $job) => $job->audit->is($audit));
    }

    public function test_re_audit_does_not_queue_a_duplicate_while_an_audit_is_running(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => 'https://smile.ae']);
        WebsiteAudit::factory()->pending()->create(['business_id' => $business, 'url' => 'https://smile.ae', 'normalized_url' => 'https://smile.ae']);

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['business' => $business])
            ->call('analyze');

        $this->assertSame(1, $business->websiteAudits()->count());
        Queue::assertNotPushed(AuditBusinessWebsite::class);
        $this->assertSame('An audit of this website is already queued or running.', session('status'));
    }

    public function test_re_evaluating_a_business_without_a_website_scores_it_immediately(): void
    {
        Queue::fake([AuditBusinessWebsite::class]);
        $business = Business::factory()->create(['website' => null]);

        Livewire::actingAs(User::factory()->create())
            ->test(Show::class, ['business' => $business])
            ->call('analyze');

        $this->assertNotNull($business->leadScore()->first());
        $this->assertSame(AuditStatus::NotApplicable, $business->websiteAudits()->sole()->status);
        Queue::assertNotPushed(AuditBusinessWebsite::class);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('businesses.show', Business::factory()->create()))->assertRedirect(route('login'));
    }
}

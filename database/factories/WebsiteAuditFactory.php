<?php

namespace Database\Factories;

use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteAudit>
 */
class WebsiteAuditFactory extends Factory
{
    /**
     * A completed audit of a basic website with nothing notable detected.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory()->state(['website' => 'https://example.com']),
            'status' => AuditStatus::Completed,
            'url' => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'http_status' => 200,
            'response_time_ms' => 420,
            'reachable' => true,
            'is_https' => true,
            'ssl_valid' => true,
            'pages_checked' => 1,
            'title' => 'Example',
            'has_viewport' => true,
            'has_phone' => false,
            'has_email' => false,
            'has_whatsapp' => false,
            'has_contact_form' => false,
            'has_booking' => false,
            'has_online_ordering' => false,
            'has_ecommerce' => false,
            'has_cart' => false,
            'has_arabic' => true,
            'has_english' => true,
            'signals' => [],
            'languages' => ['declared' => 'ar', 'primary' => 'ar', 'alternates' => []],
            'technologies' => [],
            'score' => 50,
            'attempts' => 1,
            'queued_at' => now(),
            'started_at' => now(),
            'audited_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => $this->blankResults(AuditStatus::Pending));
    }

    public function failed(AuditFailure $failure = AuditFailure::ConnectionFailed): static
    {
        return $this->state(fn (): array => [
            ...$this->blankResults(AuditStatus::Failed),
            'failure_type' => $failure,
            'error_message' => $failure->label(),
            'reachable' => ! $failure->meansWebsiteIsBroken(),
            'audited_at' => now(),
        ]);
    }

    public function notApplicable(): static
    {
        return $this->state(fn (): array => [
            ...$this->blankResults(AuditStatus::NotApplicable),
            'url' => null,
            'normalized_url' => null,
            'audited_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankResults(AuditStatus $status): array
    {
        return [
            'status' => $status,
            'final_url' => null,
            'http_status' => null,
            'response_time_ms' => null,
            'reachable' => null,
            'is_https' => null,
            'ssl_valid' => null,
            'pages_checked' => 0,
            'title' => null,
            'has_viewport' => null,
            'has_phone' => null,
            'has_email' => null,
            'has_whatsapp' => null,
            'has_contact_form' => null,
            'has_booking' => null,
            'has_online_ordering' => null,
            'has_ecommerce' => null,
            'has_cart' => null,
            'has_arabic' => null,
            'has_english' => null,
            'signals' => null,
            'languages' => null,
            'technologies' => null,
            'score' => null,
            'attempts' => 0,
            'started_at' => null,
            'audited_at' => null,
        ];
    }
}

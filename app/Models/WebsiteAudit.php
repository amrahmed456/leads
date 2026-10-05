<?php

namespace App\Models;

use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;
use Database\Factories\WebsiteAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One audit of a business's website (or the record that it has none).
 *
 * @property int $id
 * @property int $business_id
 * @property AuditStatus $status
 * @property string|null $url
 * @property string|null $normalized_url
 * @property string|null $final_url
 * @property int|null $http_status
 * @property int|null $response_time_ms
 * @property bool|null $reachable
 * @property bool|null $is_https
 * @property bool|null $ssl_valid
 * @property int $redirect_count
 * @property int $pages_checked
 * @property string|null $title
 * @property string|null $meta_description
 * @property string|null $primary_language
 * @property string|null $cms
 * @property bool|null $has_viewport
 * @property bool|null $has_phone
 * @property bool|null $has_email
 * @property bool|null $has_whatsapp
 * @property bool|null $has_contact_form
 * @property bool|null $has_booking
 * @property bool|null $has_online_ordering
 * @property bool|null $has_ecommerce
 * @property bool|null $has_cart
 * @property bool|null $has_arabic
 * @property bool|null $has_english
 * @property array<string, array{confidence: string, evidence: list<string>, data: array<string, mixed>}>|null $signals
 * @property array{declared: ?string, primary: ?string, alternates: list<string>}|null $languages
 * @property list<array{name: string, category: string, confidence: string}>|null $technologies
 * @property int|null $score
 * @property array{checks: list<array{key: string, label: string, points: int, max: int, passed: bool}>}|null $score_breakdown
 * @property AuditFailure|null $failure_type
 * @property string|null $error_message
 * @property int $attempts
 * @property bool $forced
 * @property int|null $reused_audit_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $audited_at
 */
#[Fillable(['url', 'normalized_url', 'queued_at'])]
class WebsiteAudit extends Model
{
    /** @use HasFactory<WebsiteAuditFactory> */
    use HasFactory;

    /**
     * Status, results and attempts change only through the mark* methods.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'redirect_count' => 0,
        'pages_checked' => 0,
        'attempts' => 0,
        'forced' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AuditStatus::class,
            'failure_type' => AuditFailure::class,
            'http_status' => 'integer',
            'response_time_ms' => 'integer',
            'reachable' => 'boolean',
            'is_https' => 'boolean',
            'ssl_valid' => 'boolean',
            'redirect_count' => 'integer',
            'pages_checked' => 'integer',
            'has_viewport' => 'boolean',
            'has_phone' => 'boolean',
            'has_email' => 'boolean',
            'has_whatsapp' => 'boolean',
            'has_contact_form' => 'boolean',
            'has_booking' => 'boolean',
            'has_online_ordering' => 'boolean',
            'has_ecommerce' => 'boolean',
            'has_cart' => 'boolean',
            'has_arabic' => 'boolean',
            'has_english' => 'boolean',
            'signals' => 'array',
            'languages' => 'array',
            'technologies' => 'array',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'attempts' => 'integer',
            'forced' => 'boolean',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'audited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The audit whose results were copied (same website, audited recently).
     *
     * @return BelongsTo<WebsiteAudit, $this>
     */
    public function reusedAudit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reused_audit_id');
    }

    /**
     * Whether the homepage was fetched and its HTML analyzed. Only then do the
     * has_* columns describe the website; otherwise they are unknown.
     */
    public function isAnalyzed(): bool
    {
        return $this->status === AuditStatus::Completed;
    }

    /**
     * Whether the audit shows the listed website is down or broken.
     */
    public function showsBrokenWebsite(): bool
    {
        return $this->status === AuditStatus::Failed && $this->failure_type?->meansWebsiteIsBroken() === true;
    }

    public function isMultilingual(): bool
    {
        return ($this->has_arabic && $this->has_english) || ($this->languages['alternates'] ?? []) !== [];
    }

    public function signalConfidence(WebsiteSignal $signal): Confidence
    {
        return Confidence::tryFrom($this->signals[$signal->value]['confidence'] ?? '') ?? Confidence::None;
    }

    /**
     * @return list<string>
     */
    public function signalEvidence(WebsiteSignal $signal): array
    {
        return $this->signals[$signal->value]['evidence'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function signalData(WebsiteSignal $signal): array
    {
        return $this->signals[$signal->value]['data'] ?? [];
    }

    /**
     * The main piece of evidence for a signal, for display.
     */
    public function signalDetail(WebsiteSignal $signal): ?string
    {
        $evidence = $this->signalEvidence($signal)[0] ?? null;

        if ($evidence === null) {
            return null;
        }

        return $this->signalConfidence($signal) === Confidence::Low ? "Hint only: {$evidence}" : $evidence;
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => AuditStatus::Running,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    public function markRetrying(AuditFailure $failure, string $message): void
    {
        $this->forceFill([
            'status' => AuditStatus::Pending,
            'failure_type' => $failure,
            'error_message' => Str::limit("Attempt {$this->attempts} failed: {$message} Retrying automatically.", 1000),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public function markCompleted(array $results): void
    {
        $this->forceFill([
            ...$results,
            'status' => AuditStatus::Completed,
            'failure_type' => null,
            'error_message' => null,
            'audited_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $results  Whatever is known despite the failure (HTTP status, URLs...)
     */
    public function markFailed(AuditFailure $failure, string $message, array $results = []): void
    {
        $this->forceFill([
            ...$results,
            'status' => AuditStatus::Failed,
            'failure_type' => $failure,
            'error_message' => Str::limit($message, 1000),
            'audited_at' => now(),
        ])->save();
    }
}

<?php

namespace App\Models;

use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Enums\SourceRole;
use App\Sources\SourceRegistry;
use Database\Factories\BusinessEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fact a source attests about a business, with its provenance.
 *
 * @property int $id
 * @property int $business_id
 * @property int|null $business_source_id
 * @property EvidenceFact $fact_type
 * @property string $value
 * @property string $value_normalized
 * @property string $provider
 * @property SourceRole $source_role
 * @property string $independence_group
 * @property string|null $source_record_id
 * @property string|null $source_url
 * @property float|null $confidence
 * @property EvidenceStatus $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon $observed_at
 * @property Carbon $last_seen_at
 * @property string $fingerprint
 */
#[Fillable([
    'business_source_id', 'fact_type', 'value', 'value_normalized', 'provider', 'source_role', 'independence_group',
    'source_record_id', 'source_url', 'confidence', 'status', 'metadata', 'observed_at', 'last_seen_at', 'fingerprint',
])]
class BusinessEvidence extends Model
{
    /** @use HasFactory<BusinessEvidenceFactory> */
    use HasFactory;

    protected $table = 'business_evidence';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fact_type' => EvidenceFact::class,
            'source_role' => SourceRole::class,
            'status' => EvidenceStatus::class,
            'confidence' => 'float',
            'metadata' => 'array',
            'observed_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
     * @return BelongsTo<BusinessSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function providerLabel(): string
    {
        return app(SourceRegistry::class)->label($this->provider);
    }
}

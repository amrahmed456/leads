<?php

namespace App\Models;

use App\Enums\SourceRole;
use App\Sources\SourceRegistry;
use Database\Factories\BusinessSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A business as known to one provider, with the provenance needed to judge
 * how much it confirms: the role the source played, its independence group
 * (sources sharing an underlying dataset share a group), its confidence,
 * where the data originally came from, and why it was matched.
 *
 * @property int $id
 * @property int $business_id
 * @property string $provider
 * @property SourceRole $source_role
 * @property string $independence_group
 * @property string $provider_id
 * @property bool $is_primary
 * @property float|null $confidence
 * @property list<array<string, mixed>>|null $provenance
 * @property list<string>|null $match_reasons
 * @property string|null $source_url
 * @property array<string, mixed>|null $raw_data
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable([
    'provider', 'source_role', 'independence_group', 'provider_id', 'is_primary', 'confidence', 'provenance',
    'match_reasons', 'source_url', 'raw_data', 'first_seen_at', 'last_seen_at',
])]
class BusinessSource extends Model
{
    /** @use HasFactory<BusinessSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_role' => SourceRole::class,
            'is_primary' => 'boolean',
            'confidence' => 'float',
            'provenance' => 'array',
            'match_reasons' => 'array',
            'raw_data' => 'array',
            'first_seen_at' => 'datetime',
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
     * @return HasMany<BusinessEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(BusinessEvidence::class);
    }

    public function providerLabel(): string
    {
        return app(SourceRegistry::class)->label($this->provider);
    }
}

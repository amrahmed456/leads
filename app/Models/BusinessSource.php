<?php

namespace App\Models;

use App\Discovery\DiscoveryProviderRegistry;
use Database\Factories\BusinessSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A business as known to one provider.
 *
 * @property int $id
 * @property int $business_id
 * @property string $provider
 * @property string $provider_id
 * @property bool $is_primary
 * @property string|null $source_url
 * @property array<string, mixed>|null $raw_data
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable(['provider', 'provider_id', 'is_primary', 'source_url', 'raw_data', 'first_seen_at', 'last_seen_at'])]
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
            'is_primary' => 'boolean',
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

    public function providerLabel(): string
    {
        return app(DiscoveryProviderRegistry::class)->label($this->provider);
    }
}

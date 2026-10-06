<?php

namespace App\Models;

use Database\Factories\AtpSpiderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An AllThePlaces spider (one brand's official store locator) managed by
 * the admin. Only these spiders are ever downloaded.
 *
 * @property int $id
 * @property string $spider
 * @property string $brand_name
 * @property string|null $brand_wikidata
 * @property string|null $country_code
 * @property bool $enabled
 * @property string|null $notes
 * @property string|null $last_run_id
 * @property string|null $output_url
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_success_at
 * @property int $feature_count
 * @property int $stored_count
 * @property int $matched_count
 * @property string|null $last_error
 * @property array<string, mixed>|null $last_result
 */
#[Fillable(['spider', 'brand_name', 'brand_wikidata', 'country_code', 'enabled', 'notes'])]
class AtpSpider extends Model
{
    /** @use HasFactory<AtpSpiderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'feature_count' => 0,
        'stored_count' => 0,
        'matched_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime',
            'feature_count' => 'integer',
            'stored_count' => 'integer',
            'matched_count' => 'integer',
            'last_result' => 'array',
        ];
    }

    /**
     * @return HasMany<SourcePlace, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(SourcePlace::class, 'dataset_id')->where('dataset_type', SourcePlace::DATASET_ATP_SPIDER);
    }
}

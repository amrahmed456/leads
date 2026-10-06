<?php

namespace App\Models;

use App\Enums\BrandScope;
use App\Enums\EntityType;
use App\Support\Normalization\Text;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A brand (a chain's identity), as known from the brand registry (NSI),
 * Wikidata, Overture or first-party store locators.
 *
 * @property int $id
 * @property string $name
 * @property string $name_normalized
 * @property string|null $wikidata_id
 * @property string|null $nsi_id
 * @property int|null $organization_id
 * @property BrandScope $scope
 * @property EntityType|null $entity_type
 * @property list<string>|null $countries
 * @property list<string>|null $categories
 * @property list<string>|null $official_domains
 * @property int|null $location_count
 * @property int|null $country_count
 * @property string $source
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $wikidata_fetched_at
 */
#[Fillable([
    'name', 'wikidata_id', 'nsi_id', 'organization_id', 'scope', 'entity_type', 'countries', 'categories',
    'official_domains', 'location_count', 'country_count', 'source', 'metadata', 'wikidata_fetched_at',
])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => BrandScope::class,
            'entity_type' => EntityType::class,
            'countries' => 'array',
            'categories' => 'array',
            'official_domains' => 'array',
            'location_count' => 'integer',
            'country_count' => 'integer',
            'metadata' => 'array',
            'wikidata_fetched_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            $brand->name_normalized = Text::matchKey($brand->name);
        });

        static::saved(function (Brand $brand): void {
            $brand->addAlias($brand->name, $brand->source);
        });
    }

    /**
     * @return HasMany<BrandAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(BrandAlias::class);
    }

    /**
     * @return HasMany<Business, $this>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function addAlias(?string $alias, string $source): void
    {
        $alias = Text::clean($alias, 191);
        $normalized = $alias === null ? '' : Text::matchKey($alias);

        if ($alias === null || $normalized === '' || mb_strlen($normalized) < 2) {
            return;
        }

        $this->aliases()->firstOrCreate(['alias_normalized' => mb_substr($normalized, 0, 191)], ['alias' => $alias, 'source' => $source]);
    }

    /**
     * Whether the brand is known to operate in more than one country.
     */
    public function isMultiCountry(): bool
    {
        return ($this->country_count ?? count($this->countries ?? [])) > 1 || in_array('001', $this->countries ?? [], true);
    }
}

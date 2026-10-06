<?php

namespace App\Models;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Support\Normalization\Text;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $alternate_name
 * @property string $name_normalized
 * @property BusinessCategory|null $category
 * @property string|null $type
 * @property int|null $brand_id
 * @property int|null $brand_confidence
 * @property string|null $brand_source
 * @property EntityType|null $entity_type
 * @property int|null $entity_confidence
 * @property list<string>|null $entity_reason_codes
 * @property list<array{code: string, label: string}>|null $entity_reasons
 * @property Carbon|null $classified_at
 * @property string|null $country_code
 * @property string|null $city
 * @property string|null $address
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $email
 * @property array<string, string>|null $social_links
 * @property string|null $opening_hours
 * @property string|null $operating_status
 * @property float|null $rating
 * @property int|null $reviews_count
 * @property int $evidence_count
 * @property int $independent_source_count
 * @property bool $has_identity_conflict
 * @property list<array{fact: string, severity: string, values: list<array{value: string, provider: string, group: string}>, preferred: string}>|null $identity_conflicts
 * @property Carbon $first_discovered_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_verified_at
 */
#[Fillable([
    'name', 'alternate_name', 'category', 'type', 'country_code', 'city', 'address',
    'latitude', 'longitude', 'phone', 'website', 'email', 'social_links', 'opening_hours',
    'rating', 'reviews_count', 'first_discovered_at', 'last_seen_at', 'operating_status',
])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BusinessCategory::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'social_links' => 'array',
            'rating' => 'float',
            'reviews_count' => 'integer',
            'brand_confidence' => 'integer',
            'entity_type' => EntityType::class,
            'entity_confidence' => 'integer',
            'entity_reason_codes' => 'array',
            'entity_reasons' => 'array',
            'classified_at' => 'datetime',
            'evidence_count' => 'integer',
            'independent_source_count' => 'integer',
            'has_identity_conflict' => 'boolean',
            'identity_conflicts' => 'array',
            'first_discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Business $business): void {
            $business->name_normalized = Text::matchKey($business->name);
        });
    }

    /**
     * @return HasMany<BusinessSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(BusinessSource::class);
    }

    /**
     * @return HasMany<BusinessEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(BusinessEvidence::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Graph edges starting at this business (branch_of, parent_organization...).
     *
     * @return MorphMany<EntityRelationship, $this>
     */
    public function relationships(): MorphMany
    {
        return $this->morphMany(EntityRelationship::class, 'subject');
    }

    /**
     * @return HasMany<WebsiteAudit, $this>
     */
    public function websiteAudits(): HasMany
    {
        return $this->hasMany(WebsiteAudit::class);
    }

    /**
     * @return HasOne<WebsiteAudit, $this>
     */
    public function latestWebsiteAudit(): HasOne
    {
        return $this->hasOne(WebsiteAudit::class)->latestOfMany();
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return HasOne<LeadScore, $this>
     */
    public function leadScore(): HasOne
    {
        return $this->hasOne(LeadScore::class);
    }

    /**
     * @return BelongsToMany<SearchCampaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(SearchCampaign::class)->withTimestamps();
    }

    public function typeLabel(): ?string
    {
        return $this->type === null ? null : Str::headline($this->type);
    }

    public function websiteHost(): ?string
    {
        $host = $this->website === null ? null : parse_url($this->website, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', $host) : null;
    }

    /**
     * Match name, phone, website, e-mail or address.
     *
     * @param  Builder<Business>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.$this->escapeLike($term).'%';
        $nameKey = Text::matchKey($term);
        $digits = ltrim((string) preg_replace('/\D+/', '', Text::asciiDigits($term)), '0');

        $query->where(function (Builder $query) use ($like, $nameKey, $digits): void {
            $query->where('name', 'like', $like)
                ->orWhere('alternate_name', 'like', $like)
                ->orWhere('website', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('address', 'like', $like);

            if ($nameKey !== '') {
                $query->orWhere('name_normalized', 'like', '%'.$this->escapeLike($nameKey).'%');
            }

            // "0501234567" should find "+966501234567".
            if (strlen($digits) >= 5) {
                $query->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

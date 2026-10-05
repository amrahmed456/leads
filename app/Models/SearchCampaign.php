<?php

namespace App\Models;

use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\GeocodedLocation;
use App\Discovery\DiscoveryProviderRegistry;
use App\Enums\BusinessCategory;
use App\Enums\CampaignStatus;
use App\Enums\Country;
use Database\Factories\SearchCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string $provider
 * @property Country $country_code
 * @property string $city
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $resolved_location
 * @property int $radius_meters
 * @property BusinessCategory $category
 * @property list<string>|null $keywords
 * @property CampaignStatus $status
 * @property int $results_count
 * @property int $new_businesses_count
 * @property int $skipped_count
 * @property bool $results_truncated
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
#[Fillable(['name', 'provider', 'country_code', 'city', 'latitude', 'longitude', 'radius_meters', 'category', 'keywords'])]
class SearchCampaign extends Model
{
    /** @use HasFactory<SearchCampaignFactory> */
    use HasFactory;

    /**
     * Status and run statistics are not fillable; they change only through
     * the mark* methods below.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'results_count' => 0,
        'new_businesses_count' => 0,
        'skipped_count' => 0,
        'results_truncated' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'country_code' => Country::class,
            'category' => BusinessCategory::class,
            'status' => CampaignStatus::class,
            'keywords' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'results_count' => 'integer',
            'new_businesses_count' => 'integer',
            'skipped_count' => 'integer',
            'results_truncated' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Business, $this>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->withTimestamps();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function radiusKm(): float
    {
        return round($this->radius_meters / 1000, 2);
    }

    public function providerLabel(): string
    {
        return app(DiscoveryProviderRegistry::class)->label($this->provider);
    }

    public function toDiscoveryQuery(): DiscoveryQuery
    {
        if (! $this->hasCoordinates()) {
            throw new LogicException("Campaign [{$this->id}] has no coordinates yet.");
        }

        return new DiscoveryQuery(
            country: $this->country_code,
            city: $this->city,
            latitude: $this->latitude,
            longitude: $this->longitude,
            radiusMeters: $this->radius_meters,
            category: $this->category,
            keywords: $this->keywords ?? [],
        );
    }

    public function useLocation(GeocodedLocation $location): void
    {
        $this->forceFill([
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'resolved_location' => $location->displayName,
        ])->save();
    }

    public function markQueued(): void
    {
        $this->forceFill([
            'status' => CampaignStatus::Pending,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => CampaignStatus::Running,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    public function markRetrying(int $attempt, string $reason): void
    {
        $this->forceFill([
            'status' => CampaignStatus::Pending,
            'error_message' => Str::limit("Attempt {$attempt} failed: {$reason} Retrying automatically.", 2000),
        ])->save();
    }

    public function markCompleted(int $results, int $newBusinesses, int $skipped, bool $truncated): void
    {
        $this->forceFill([
            'status' => CampaignStatus::Completed,
            'results_count' => $results,
            'new_businesses_count' => $newBusinesses,
            'skipped_count' => $skipped,
            'results_truncated' => $truncated,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => CampaignStatus::Failed,
            'error_message' => Str::limit($reason, 2000),
            'completed_at' => null,
        ])->save();
    }

    /**
     * Split user input such as "مطعم, restaurant، cafe" into unique keywords.
     *
     * @return list<string>
     */
    public static function parseKeywords(?string $input): array
    {
        $keywords = [];

        foreach (preg_split('/[,،;\n]+/u', (string) $input) ?: [] as $keyword) {
            $keyword = trim((string) preg_replace('/\s+/u', ' ', $keyword));

            if ($keyword !== '') {
                $keywords[mb_strtolower($keyword)] ??= $keyword;
            }
        }

        return array_values($keywords);
    }
}

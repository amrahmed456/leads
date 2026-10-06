<?php

namespace App\Models;

use App\Enums\BusinessCategory;
use App\Support\Geo;
use App\Support\Normalization\Text;
use Database\Factories\SourcePlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A place from a file-based source (an imported Overture extract or a
 * synced AllThePlaces spider), kept locally so discovery and matching never
 * query a global dataset.
 *
 * @property int $id
 * @property string $source
 * @property string $source_record_id
 * @property string $dataset_type
 * @property int|null $dataset_id
 * @property string $name
 * @property string $name_normalized
 * @property string|null $brand_name
 * @property string|null $brand_wikidata
 * @property string|null $operator
 * @property string|null $branch
 * @property string|null $category
 * @property BusinessCategory|null $mapped_category
 * @property array<string, mixed>|null $taxonomy
 * @property float|null $confidence
 * @property string|null $operating_status
 * @property float $latitude
 * @property float $longitude
 * @property string|null $country_code
 * @property string|null $city
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $email
 * @property array<string, string>|null $socials
 * @property list<array<string, mixed>>|null $provenance
 * @property string|null $source_url
 * @property array<string, mixed>|null $extra
 * @property Carbon $observed_at
 */
#[Fillable([
    'source', 'source_record_id', 'dataset_type', 'dataset_id', 'name', 'brand_name', 'brand_wikidata', 'operator',
    'branch', 'category', 'mapped_category', 'taxonomy', 'confidence', 'operating_status', 'latitude', 'longitude',
    'country_code', 'city', 'address', 'phone', 'website', 'email', 'socials', 'provenance', 'source_url',
    'extra', 'observed_at',
])]
class SourcePlace extends Model
{
    /** @use HasFactory<SourcePlaceFactory> */
    use HasFactory;

    public const DATASET_OVERTURE_IMPORT = 'overture_import';

    public const DATASET_ATP_SPIDER = 'atp_spider';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mapped_category' => BusinessCategory::class,
            'taxonomy' => 'array',
            'confidence' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'socials' => 'array',
            'provenance' => 'array',
            'extra' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SourcePlace $place): void {
            $place->name_normalized = Text::matchKey($place->name);
        });
    }

    /**
     * Places inside the bounding box of a circle (refine with Geo::distanceMeters).
     *
     * @param  Builder<SourcePlace>  $query
     */
    #[Scope]
    protected function near(Builder $query, float $latitude, float $longitude, float $radiusMeters): void
    {
        [$latDelta, $lonDelta] = Geo::boundingDeltas($latitude, $radiusMeters);

        $query->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
            ->whereBetween('longitude', [$longitude - $lonDelta, $longitude + $lonDelta]);
    }

    /**
     * Insert or refresh many places at once (by source + record ID). Bulk
     * upserts skip model events, so derived and JSON columns are prepared here.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function upsertRows(array $rows, string $datasetType, ?int $datasetId): int
    {
        $columns = [
            'source', 'source_record_id', 'name', 'brand_name', 'brand_wikidata', 'operator', 'branch', 'category',
            'mapped_category', 'taxonomy', 'confidence', 'operating_status', 'latitude', 'longitude', 'country_code',
            'city', 'address', 'phone', 'website', 'email', 'socials', 'provenance', 'source_url', 'extra',
        ];
        $now = now();
        $prepared = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $values[$column] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
            }

            $prepared[$values['source'].'|'.$values['source_record_id']] = [
                ...$values,
                'dataset_type' => $datasetType,
                'dataset_id' => $datasetId,
                'name_normalized' => Text::matchKey((string) $values['name']),
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($prepared === []) {
            return 0;
        }

        $update = array_values(array_diff(array_keys(reset($prepared)), ['source', 'source_record_id', 'created_at']));
        static::query()->upsert(array_values($prepared), ['source', 'source_record_id'], $update);

        return count($prepared);
    }

    public function distanceTo(float $latitude, float $longitude): float
    {
        return Geo::distanceMeters($this->latitude, $this->longitude, $latitude, $longitude);
    }
}

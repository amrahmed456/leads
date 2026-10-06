<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\OvertureImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A bounded geographic Overture Places extract and the progress of its
 * streamed, resumable import.
 *
 * @property int $id
 * @property string $label
 * @property string $mode
 * @property string $format
 * @property string|null $country_code
 * @property string|null $city
 * @property string|null $region
 * @property array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}|null $bbox
 * @property Carbon|null $release_date
 * @property string|null $source_release
 * @property string|null $file_path
 * @property string|null $original_name
 * @property string|null $remote_url
 * @property int $file_size
 * @property ImportStatus $status
 * @property int $rows_processed
 * @property int $rows_imported
 * @property int $rows_skipped
 * @property int $rows_invalid
 * @property int $byte_offset
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'label', 'mode', 'format', 'country_code', 'city', 'region', 'bbox', 'release_date', 'source_release',
    'file_path', 'original_name', 'remote_url', 'file_size', 'expires_at',
])]
class OvertureImport extends Model
{
    /** @use HasFactory<OvertureImportFactory> */
    use HasFactory;

    public const FORMATS = ['geojson' => 'GeoJSON', 'ndjson' => 'GeoJSONSeq / NDJSON', 'csv' => 'CSV'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'file_size' => 0,
        'rows_processed' => 0,
        'rows_imported' => 0,
        'rows_skipped' => 0,
        'rows_invalid' => 0,
        'byte_offset' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bbox' => 'array',
            'release_date' => 'date',
            'file_size' => 'integer',
            'status' => ImportStatus::class,
            'rows_processed' => 'integer',
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'rows_invalid' => 'integer',
            'byte_offset' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SourcePlace, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(SourcePlace::class, 'dataset_id')->where('dataset_type', SourcePlace::DATASET_OVERTURE_IMPORT);
    }

    public function progressPercent(): ?int
    {
        return $this->file_size > 0 ? min(100, (int) floor($this->byte_offset / $this->file_size * 100)) : null;
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => ImportStatus::Running, 'started_at' => $this->started_at ?? now(), 'error_message' => null])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill(['status' => ImportStatus::Failed, 'error_message' => Str::limit($reason, 2000)])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill(['status' => ImportStatus::Completed, 'completed_at' => now(), 'error_message' => null])->save();
    }
}

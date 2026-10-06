<?php

namespace App\Models;

use App\Enums\SourceRunStatus;
use App\Sources\SourceRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One discovery source's part of a campaign run. A failing source fails only
 * its own run; the campaign completes with the sources that succeeded.
 *
 * @property int $id
 * @property int $search_campaign_id
 * @property string $source
 * @property SourceRunStatus $status
 * @property int $results_count
 * @property int $created_count
 * @property int $merged_count
 * @property int $skipped_count
 * @property bool $truncated
 * @property int $attempts
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
#[Fillable(['source'])]
class CampaignSourceRun extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'results_count' => 0,
        'created_count' => 0,
        'merged_count' => 0,
        'skipped_count' => 0,
        'truncated' => false,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourceRunStatus::class,
            'results_count' => 'integer',
            'created_count' => 'integer',
            'merged_count' => 'integer',
            'skipped_count' => 'integer',
            'truncated' => 'boolean',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SearchCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SearchCampaign::class, 'search_campaign_id');
    }

    public function sourceLabel(): string
    {
        return app(SourceRegistry::class)->label($this->source);
    }

    public function reset(): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Pending, 'results_count' => 0, 'created_count' => 0, 'merged_count' => 0,
            'skipped_count' => 0, 'truncated' => false, 'attempts' => 0, 'error_message' => null,
            'started_at' => null, 'completed_at' => null,
        ])->save();
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Running,
            'attempts' => $this->attempts + 1,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function markRetrying(string $reason): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Pending,
            'error_message' => Str::limit("Attempt {$this->attempts} failed: {$reason} Retrying automatically.", 2000),
        ])->save();
    }

    public function markCompleted(int $results, int $created, int $merged, int $skipped, bool $truncated): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Completed,
            'results_count' => $results,
            'created_count' => $created,
            'merged_count' => $merged,
            'skipped_count' => $skipped,
            'truncated' => $truncated,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Failed,
            'error_message' => Str::limit($reason, 2000),
            'completed_at' => now(),
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => SourceRunStatus::Skipped,
            'error_message' => Str::limit($reason, 2000),
            'completed_at' => now(),
        ])->save();
    }
}

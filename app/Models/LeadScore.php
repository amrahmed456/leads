<?php

namespace App\Models;

use App\Enums\LeadGrade;
use Database\Factories\LeadScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current rule-based lead score of a business.
 *
 * @property int $id
 * @property int $business_id
 * @property int $score
 * @property LeadGrade $grade
 * @property list<array{key: string, label: string, points: int}> $breakdown
 * @property string $version
 * @property Carbon $scored_at
 */
#[Fillable(['score', 'grade', 'breakdown', 'version', 'scored_at'])]
class LeadScore extends Model
{
    /** @use HasFactory<LeadScoreFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'grade' => LeadGrade::class,
            'breakdown' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

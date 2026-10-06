<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current qualification of a business as a sales prospect: three
 * separate scores, the final eligibility status and every reason behind it.
 *
 * - data_confidence_score: is the business and its data real and current?
 * - business_fit_score: is this kind of business a good target for us?
 * - opportunity_score: does it have digital gaps we can fix?
 * - status: HOT / WARM / LOW / REVIEW_REQUIRED / DISQUALIFIED
 * - score: composite lead score used for sorting (0 when disqualified)
 *
 * @property int $id
 * @property int $business_id
 * @property int $score
 * @property LeadStatus $status
 * @property int $data_confidence_score
 * @property int $business_fit_score
 * @property int $opportunity_score
 * @property list<array{key: string, label: string, points: int}> $breakdown
 * @property list<array{code: string, label: string}>|null $eligibility_reasons
 * @property list<array{key: string, label: string, points: int}>|null $confidence_breakdown
 * @property list<array{key: string, label: string, points: int}>|null $fit_breakdown
 * @property list<array{key: string, label: string, points: int}>|null $opportunity_breakdown
 * @property string $version
 * @property Carbon $scored_at
 */
#[Fillable([
    'score', 'status', 'data_confidence_score', 'business_fit_score', 'opportunity_score', 'breakdown',
    'eligibility_reasons', 'confidence_breakdown', 'fit_breakdown', 'opportunity_breakdown', 'version', 'scored_at',
])]
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
            'status' => LeadStatus::class,
            'data_confidence_score' => 'integer',
            'business_fit_score' => 'integer',
            'opportunity_score' => 'integer',
            'breakdown' => 'array',
            'eligibility_reasons' => 'array',
            'confidence_breakdown' => 'array',
            'fit_breakdown' => 'array',
            'opportunity_breakdown' => 'array',
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

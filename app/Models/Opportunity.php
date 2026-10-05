<?php

namespace App\Models;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service the business could be offered, with the reasons it was detected.
 *
 * @property int $id
 * @property int $business_id
 * @property OpportunityType $type
 * @property OpportunityPriority $priority
 * @property list<string> $reasons
 * @property string $rule
 * @property int|null $website_audit_id
 * @property Carbon $detected_at
 */
#[Fillable(['type', 'priority', 'reasons', 'rule', 'website_audit_id', 'detected_at'])]
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OpportunityType::class,
            'priority' => OpportunityPriority::class,
            'reasons' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<WebsiteAudit, $this>
     */
    public function websiteAudit(): BelongsTo
    {
        return $this->belongsTo(WebsiteAudit::class);
    }
}

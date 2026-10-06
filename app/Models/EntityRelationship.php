<?php

namespace App\Models;

use App\Enums\RelationshipType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An edge of the brand / organization graph, e.g. Business —branch_of→
 * Brand, Brand —owned_by→ Organization, Business —parent_organization→
 * Organization. Records which source asserted it and how confidently.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property RelationshipType $relationship
 * @property string $object_type
 * @property int $object_id
 * @property int $confidence
 * @property string $provider
 * @property string|null $reason
 */
#[Fillable(['subject_type', 'subject_id', 'relationship', 'object_type', 'object_id', 'confidence', 'provider', 'reason'])]
class EntityRelationship extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship' => RelationshipType::class,
            'confidence' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function object(): MorphTo
    {
        return $this->morphTo();
    }
}

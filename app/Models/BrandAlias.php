<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A name a brand is known by ("KFC", "كنتاكي", "Kentucky Fried Chicken").
 *
 * @property int $id
 * @property int $brand_id
 * @property string $alias
 * @property string $alias_normalized
 * @property string $source
 */
#[Fillable(['alias', 'alias_normalized', 'source'])]
class BrandAlias extends Model
{
    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}

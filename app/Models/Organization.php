<?php

namespace App\Models;

use App\Support\Normalization\Text;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company, group, government body or non-profit behind brands and
 * business locations.
 *
 * @property int $id
 * @property string $name
 * @property string $name_normalized
 * @property string|null $wikidata_id
 * @property string $type
 * @property string|null $country_code
 * @property string|null $website
 * @property int|null $employees
 * @property string $source
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['name', 'wikidata_id', 'type', 'country_code', 'website', 'employees', 'source', 'metadata'])]
class Organization extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employees' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Organization $organization): void {
            $organization->name_normalized = Text::matchKey($organization->name);
        });
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached Wikidata facts for one entity (only the fields the system uses).
 *
 * @property string $id
 * @property string|null $label
 * @property string|null $label_ar
 * @property string|null $description
 * @property list<string>|null $aliases
 * @property string|null $official_website
 * @property list<string>|null $instance_of
 * @property list<string>|null $parent_ids
 * @property list<string>|null $country_ids
 * @property int|null $employees
 * @property Carbon $fetched_at
 */
#[Table(key: 'id', keyType: 'string', incrementing: false)]
#[Fillable([
    'id', 'label', 'label_ar', 'description', 'aliases', 'official_website', 'instance_of',
    'parent_ids', 'country_ids', 'employees', 'fetched_at',
])]
class WikidataEntity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'instance_of' => 'array',
            'parent_ids' => 'array',
            'country_ids' => 'array',
            'employees' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}

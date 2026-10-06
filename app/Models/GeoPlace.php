<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A GeoNames city with its alternate names, for city normalization.
 *
 * @property int $id
 * @property int $geonames_id
 * @property string $name
 * @property string $ascii_name
 * @property string|null $name_ar
 * @property string $search_names
 * @property string $country_code
 * @property string|null $admin1
 * @property string $feature_code
 * @property float $latitude
 * @property float $longitude
 * @property int $population
 */
#[Fillable([
    'geonames_id', 'name', 'ascii_name', 'name_ar', 'search_names', 'country_code', 'admin1', 'feature_code',
    'latitude', 'longitude', 'population',
])]
class GeoPlace extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geonames_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'population' => 'integer',
        ];
    }
}

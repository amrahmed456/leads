<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An OpenAddresses address point, used only as address context.
 *
 * @property int $id
 * @property string $source_id
 * @property string $dataset
 * @property string|null $number
 * @property string|null $street
 * @property string|null $unit
 * @property string|null $district
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postcode
 * @property string|null $country_code
 * @property float $latitude
 * @property float $longitude
 */
#[Fillable([
    'source_id', 'dataset', 'number', 'street', 'unit', 'district', 'city', 'region', 'postcode',
    'country_code', 'latitude', 'longitude',
])]
class AddressPoint extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function formatted(): string
    {
        return implode(', ', array_filter([
            trim(($this->number ?? '').' '.($this->street ?? '')),
            $this->unit,
            $this->district,
            $this->city,
            $this->postcode,
        ]));
    }
}

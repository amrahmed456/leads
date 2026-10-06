<?php

namespace Database\Factories;

use App\Models\OvertureImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertureImport>
 */
class OvertureImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => 'Riyadh extract',
            'mode' => 'path',
            'format' => 'ndjson',
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'bbox' => null,
            'file_path' => 'imports/overture/test.ndjson',
            'file_size' => 0,
        ];
    }
}

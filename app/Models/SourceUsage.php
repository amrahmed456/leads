<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily usage of one source. Changed only through QuotaManager.
 *
 * @property int $id
 * @property string $source
 * @property Carbon $date
 * @property int $requests
 * @property int $units
 * @property int $errors
 * @property bool $exhausted
 */
#[Table('source_usage')]
#[Fillable(['source', 'date'])]
class SourceUsage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'requests' => 'integer',
            'units' => 'integer',
            'errors' => 'integer',
            'exhausted' => 'boolean',
        ];
    }
}

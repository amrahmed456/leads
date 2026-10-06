<?php

namespace App\Models;

use App\Enums\SourceHealth;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Runtime state of a data source: the admin's settings, encrypted
 * credentials and health. Static metadata lives in config/sources.php.
 *
 * @property int $id
 * @property string $key
 * @property bool|null $enabled
 * @property array<string, string>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property SourceHealth $health_status
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_error_at
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 * @property string|null $notes
 */
#[Fillable(['key', 'enabled', 'settings', 'notes'])]
#[Hidden(['credentials'])]
class DataSource extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'health_status' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'health_status' => SourceHealth::class,
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ImportOvertureExtract;
use App\Models\OvertureImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Imports an Overture extract already on the server (e.g. uploaded with
 * the cPanel File Manager), for files too large for a browser upload.
 */
class ImportOvertureCommand extends Command
{
    protected $signature = 'overture:import
                            {path : File path relative to storage/app/private (or absolute inside it)}
                            {--format= : geojson, ndjson or csv (default: from the extension)}
                            {--label= : Name shown in the admin}
                            {--country= : ISO country code}
                            {--city= : City or area}
                            {--bbox= : min_lon,min_lat,max_lon,max_lat — places outside are skipped}
                            {--release= : Overture release identifier, e.g. 2025-09-24.0}
                            {--sync : Import now in this process}';

    protected $description = 'Import a bounded Overture Places extract from a file on the server';

    public function handle(): int
    {
        $disk = Storage::disk(config('sources.overture.disk'));
        $root = rtrim(str_replace('\\', '/', $disk->path('')), '/').'/';
        $path = ltrim(str_replace([str_replace('/', '\\', $root), $root], '', str_replace('\\', '/', (string) $this->argument('path'))), '/');

        if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
            $this->error("File not found in {$root}: {$this->argument('path')}");

            return self::FAILURE;
        }

        $format = $this->option('format') ?? match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'geojson', 'json' => 'geojson',
            'csv' => 'csv',
            default => 'ndjson',
        };

        if (! array_key_exists($format, OvertureImport::FORMATS)) {
            $this->error('The format must be geojson, ndjson or csv.');

            return self::FAILURE;
        }

        $bbox = null;
        if ($this->option('bbox') !== null) {
            $parts = array_map('floatval', explode(',', (string) $this->option('bbox')));

            if (count($parts) !== 4) {
                $this->error('--bbox must be min_lon,min_lat,max_lon,max_lat');

                return self::FAILURE;
            }

            $bbox = array_combine(['min_lon', 'min_lat', 'max_lon', 'max_lat'], $parts);
        }

        $import = OvertureImport::query()->create([
            'label' => $this->option('label') ?? basename($path),
            'mode' => 'path',
            'format' => $format,
            'country_code' => $this->option('country') !== null ? strtoupper((string) $this->option('country')) : null,
            'city' => $this->option('city'),
            'bbox' => $bbox,
            'source_release' => $this->option('release'),
            'file_path' => $path,
            'original_name' => basename($path),
            'file_size' => (int) $disk->size($path),
        ]);

        if (! $this->option('sync')) {
            ImportOvertureExtract::dispatch($import);
            $this->info("Import #{$import->id} queued.");

            return self::SUCCESS;
        }

        ImportOvertureExtract::dispatchSync($import);
        $import->refresh();

        $this->table(['Status', 'Rows', 'Imported', 'Skipped', 'Invalid', 'Error'], [[
            $import->status->label(), $import->rows_processed, $import->rows_imported, $import->rows_skipped, $import->rows_invalid, $import->error_message ?? '—',
        ]]);

        return $import->status->value === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}

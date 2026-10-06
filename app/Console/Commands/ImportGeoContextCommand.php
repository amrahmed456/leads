<?php

namespace App\Console\Commands;

use App\Sources\GeoNames\GeoNamesImporter;
use App\Sources\OpenAddresses\OpenAddressesImporter;
use App\Support\BoundedDownloader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Imports geographic context: GeoNames cities (city normalization) or an
 * OpenAddresses CSV (address context). Neither creates businesses.
 */
class ImportGeoContextCommand extends Command
{
    protected $signature = 'geo:import
                            {type : geonames or openaddresses}
                            {path? : Local file (GeoNames .txt, OpenAddresses .csv); GeoNames downloads cities15000 when omitted}
                            {--dataset= : OpenAddresses dataset name, e.g. sa/countrywide}
                            {--country= : ISO country code of the OpenAddresses file}';

    protected $description = 'Import GeoNames cities or OpenAddresses points for normalization and matching';

    public function handle(GeoNamesImporter $geonames, OpenAddressesImporter $addresses, BoundedDownloader $downloader): int
    {
        if ($this->argument('type') === 'openaddresses') {
            if ($this->argument('path') === null || ! is_file((string) $this->argument('path'))) {
                $this->error('Give the path of an OpenAddresses CSV file.');

                return self::FAILURE;
            }

            $summary = $addresses->import((string) $this->argument('path'), (string) ($this->option('dataset') ?? basename((string) $this->argument('path'))), $this->option('country'));
            $this->info("Imported {$summary['imported']} address points ({$summary['invalid']} invalid rows skipped).");

            return self::SUCCESS;
        }

        if ($this->argument('type') !== 'geonames') {
            $this->error('The type must be geonames or openaddresses.');

            return self::FAILURE;
        }

        $path = $this->argument('path');

        if ($path === null) {
            if (! class_exists(ZipArchive::class)) {
                $this->error('The PHP zip extension is missing: download cities15000.zip, extract it and pass the .txt path.');

                return self::FAILURE;
            }

            $zip = Storage::disk('local')->path('imports/geonames/cities.zip');
            $downloader->download((string) config('sources.geonames.cities_url'), $zip, 20 * 1_048_576);

            $archive = new ZipArchive;
            $archive->open($zip);
            $archive->extractTo(dirname($zip));
            $name = $archive->getNameIndex(0);
            $archive->close();
            @unlink($zip);
            $path = dirname($zip).'/'.$name;
        }

        $count = $geonames->import((string) $path);
        $this->info("Imported {$count} cities.");

        return self::SUCCESS;
    }
}

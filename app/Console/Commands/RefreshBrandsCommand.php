<?php

namespace App\Console\Commands;

use App\Identity\Brands\NsiBrandImporter;
use App\Jobs\RefreshBrandRegistry;
use App\Models\Brand;
use App\Sources\SourceRegistry;
use Illuminate\Console\Command;

class RefreshBrandsCommand extends Command
{
    protected $signature = 'brands:refresh
                            {--category=* : Only these NSI categories, e.g. brands/amenity/fast_food}
                            {--file= : Import a local NSI category file instead of downloading (use with one --category)}
                            {--sync : Run in this process instead of queueing}';

    protected $description = 'Refresh the local brand registry from the Name Suggestion Index';

    public function handle(NsiBrandImporter $importer, SourceRegistry $sources): int
    {
        $categories = $this->option('category') ?: null;

        if ($this->option('file') !== null) {
            if (count($categories ?? []) !== 1) {
                $this->error('Give exactly one --category with --file.');

                return self::FAILURE;
            }

            $count = $importer->importFile((string) $this->option('file'), $categories[0]);
            $this->info("Imported {$count} brands. The registry now has ".Brand::count().' brands.');

            return self::SUCCESS;
        }

        if (! $sources->isAvailable('nsi')) {
            $this->warn('The Name Suggestion Index is disabled in Settings → Data Sources.');

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            RefreshBrandRegistry::dispatch($categories);
            $this->info('Brand registry refresh queued.');

            return self::SUCCESS;
        }

        $summary = $importer->refresh($categories);
        $this->info("Imported {$summary['brands']} brands from {$summary['files']} file(s). The registry now has ".Brand::count().' brands.');

        foreach ($summary['failed'] as $category => $error) {
            $this->warn("{$category}: {$error}");
        }

        return self::SUCCESS;
    }
}

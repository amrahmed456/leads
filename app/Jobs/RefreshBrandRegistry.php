<?php

namespace App\Jobs;

use App\Identity\Brands\NsiBrandImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the local brand registry from the Name Suggestion Index.
 * A failing category file never stops the others.
 */
class RefreshBrandRegistry implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    /**
     * @param  list<string>|null  $categories  Defaults to config('sources.nsi.categories')
     */
    public function __construct(public ?array $categories = null)
    {
        $this->onQueue(config('sources.queue'));
    }

    public function handle(NsiBrandImporter $importer): void
    {
        $summary = $importer->refresh($this->categories);

        Log::channel('discovery')->info('Brand registry refreshed.', $summary);
    }
}

<?php

namespace App\Jobs;

use App\Identity\Brands\BrandEnricher;
use App\Models\Brand;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enriches one brand from Wikidata (canonical identity, official domain,
 * aliases, parent organization), then re-qualifies the brand's businesses.
 * Only brands with a Wikidata ID are ever queued, and results are cached,
 * so request volume stays tiny.
 */
class EnrichBusinessBrand implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Brand $brand)
    {
        $this->onQueue(config('sources.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brand->getKey();
    }

    public function handle(BrandEnricher $enricher): void
    {
        if (! $enricher->needsEnrichment($this->brand) || ! $enricher->enrich($this->brand)) {
            return;
        }

        // A new official domain, scope or enterprise flag can change the brand's branches.
        $this->brand->businesses()->select('id')->chunkById(100, function ($businesses): void {
            RecalculateLeadEligibility::dispatch($businesses->modelKeys());
        });
    }
}

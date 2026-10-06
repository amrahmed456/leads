<?php

namespace App\Identity\Brands;

use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use App\Support\BoundedDownloader;
use App\Support\DownloadException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the local brand registry from the OpenStreetMap Name Suggestion
 * Index. Only the configured category files are downloaded (each small),
 * and only brands active worldwide or in the configured countries are
 * kept. The registry detects brands; it never discovers businesses.
 *
 * @see https://github.com/osmlab/name-suggestion-index/tree/main/data/brands
 */
final class NsiBrandImporter
{
    /** Tags holding names a brand is known by. */
    private const NAME_TAGS = ['brand', 'name', 'brand:en', 'brand:ar', 'name:en', 'name:ar', 'official_name', 'short_name'];

    /**
     * @param  list<string>  $categories  e.g. "brands/amenity/fast_food"
     * @param  list<string>  $countries  Lower-case ISO codes
     * @param  list<string>  $enterpriseCategories
     */
    public function __construct(
        private readonly BrandRegistry $registry,
        private readonly BoundedDownloader $downloader,
        private readonly QuotaManager $quotas,
        private readonly SourceRegistry $sources,
        private readonly string $baseUrl,
        private readonly array $categories,
        private readonly array $countries,
        private readonly array $enterpriseCategories,
        private readonly int $maxFileBytes,
    ) {}

    /**
     * Download and import every configured category file. A failing file
     * is reported and skipped; the others still import.
     *
     * @param  list<string>|null  $categories  Defaults to the configured list
     * @return array{files: int, brands: int, failed: array<string, string>}
     */
    public function refresh(?array $categories = null): array
    {
        $summary = ['files' => 0, 'brands' => 0, 'failed' => []];

        foreach ($categories ?? $this->categories as $category) {
            if (! preg_match('#^brands/[a-z_]+/[a-z_]+$#', $category)) {
                $summary['failed'][$category] = 'Invalid category name.';

                continue;
            }

            $path = Storage::disk('local')->path('imports/nsi/'.str_replace('/', '-', $category).'.json');

            try {
                $this->quotas->consume('nsi');
                $this->downloader->download(rtrim($this->baseUrl, '/')."/{$category}.json", $path, $this->maxFileBytes);
                $summary['brands'] += $this->importFile($path, $category);
                $summary['files']++;
            } catch (DownloadException|Throwable $e) {
                $summary['failed'][$category] = $e->getMessage();
                $this->sources->recordFailure('nsi', "{$category}: {$e->getMessage()}");
            } finally {
                @unlink($path);
            }
        }

        if ($summary['files'] > 0) {
            $this->sources->recordSuccess('nsi');
        }

        return $summary;
    }

    /**
     * Import one NSI category file.
     *
     * @return int Brands imported
     */
    public function importFile(string $path, string $category): int
    {
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new \RuntimeException("{$category}: not a Name Suggestion Index category file.");
        }

        $imported = 0;

        foreach ($data['items'] as $item) {
            if (is_array($item) && $this->importItem($item, $category)) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * @param  array<mixed>  $item
     */
    private function importItem(array $item, string $category): bool
    {
        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        $name = $tags['brand'] ?? $tags['name'] ?? $item['displayName'] ?? null;
        $include = array_map('strtolower', array_filter((array) ($item['locationSet']['include'] ?? []), 'is_string'));

        if (! is_string($name) || trim($name) === '') {
            return false;
        }

        // Keep worldwide brands and brands active in our countries.
        if (! in_array('001', $include, true) && array_intersect($include, $this->countries) === []) {
            return false;
        }

        $brand = $this->registry->resolve(trim($name), $this->wikidata($tags['brand:wikidata'] ?? null), 'nsi', [
            'nsi_id' => is_string($item['id'] ?? null) ? mb_substr($item['id'], 0, 100) : null,
            'countries' => array_values(array_map(fn (string $code): string => $code === '001' ? '001' : strtoupper($code), array_filter($include, fn (string $code): bool => $code === '001' || strlen($code) === 2))),
            'categories' => [$category],
            'metadata' => ['nsi_tags' => array_intersect_key($tags, array_flip(['amenity', 'shop', 'office', 'cuisine', 'brand', 'brand:wikidata', 'operator']))],
        ]);

        foreach ([...self::NAME_TAGS, 'displayName'] as $key) {
            $brand->addAlias(is_string($tags[$key] ?? null) ? $tags[$key] : (is_string($item[$key] ?? null) ? $item[$key] : null), 'nsi');
        }

        foreach (array_filter((array) ($item['matchNames'] ?? []), 'is_string') as $matchName) {
            $brand->addAlias($matchName, 'nsi');
        }

        if (in_array($category, $this->enterpriseCategories, true)) {
            $this->registry->markEnterprise($brand);
        }

        return true;
    }

    private function wikidata(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^Q\d+$/', $value) ? $value : null;
    }
}

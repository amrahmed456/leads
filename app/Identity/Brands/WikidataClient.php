<?php

namespace App\Identity\Brands;

use App\Models\WikidataEntity;
use App\Sources\QuotaExceededException;
use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks up Wikidata entities by ID, selectively: only IDs already attached
 * to a brand (from OSM, Overture, NSI or a store locator) are requested,
 * and every result is cached in wikidata_entities. Failures are recorded
 * and return null; they never break the analysis that asked.
 */
final class WikidataClient
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly QuotaManager $quotas,
        private readonly string $entityUrl,
        private readonly int $cacheDays,
        private readonly string $userAgent,
    ) {}

    public function entity(string $id): ?WikidataEntity
    {
        $id = strtoupper(trim($id));

        if (! preg_match('/^Q\d{1,12}$/', $id)) {
            return null;
        }

        $cached = WikidataEntity::query()->find($id);

        if ($cached !== null && $cached->fetched_at->greaterThan(now()->subDays($this->cacheDays))) {
            return $cached;
        }

        if (! $this->sources->isAvailable('wikidata')) {
            return $cached;
        }

        try {
            $this->quotas->consume('wikidata');

            $response = Http::withUserAgent($this->userAgent)->acceptJson()->connectTimeout(10)->timeout(20)
                ->get(sprintf($this->entityUrl, $id));

            if ($response->failed()) {
                $this->sources->recordFailure('wikidata', "{$id}: HTTP {$response->status()}");

                return $cached;
            }

            $entity = $response->json("entities.{$id}");

            if (! is_array($entity)) {
                $this->sources->recordFailure('wikidata', "{$id}: malformed response");

                return $cached;
            }
        } catch (QuotaExceededException) {
            return $cached;
        } catch (Throwable $e) {
            $this->sources->recordFailure('wikidata', "{$id}: {$e->getMessage()}");

            return $cached;
        }

        $this->sources->recordSuccess('wikidata');

        return WikidataEntity::query()->updateOrCreate(['id' => $id], [...$this->parse($entity), 'fetched_at' => now()]);
    }

    /**
     * @param  array<mixed>  $entity
     * @return array<string, mixed>
     */
    public function parse(array $entity): array
    {
        $claims = is_array($entity['claims'] ?? null) ? $entity['claims'] : [];

        $aliases = [];
        foreach (['en', 'ar'] as $language) {
            foreach ((array) ($entity['aliases'][$language] ?? []) as $alias) {
                if (is_string($alias['value'] ?? null)) {
                    $aliases[] = $alias['value'];
                }
            }
        }

        $employees = $this->values($claims, 'P1128', 'amount')[0] ?? null;

        return [
            'label' => $this->text($entity['labels']['en']['value'] ?? null),
            'label_ar' => $this->text($entity['labels']['ar']['value'] ?? null),
            'description' => $this->text($entity['descriptions']['en']['value'] ?? null, 500),
            'aliases' => array_slice(array_values(array_unique($aliases)), 0, 30) ?: null,
            'official_website' => $this->text($this->values($claims, 'P856')[0] ?? null, 500),
            'instance_of' => $this->entityIds($claims, 'P31') ?: null,
            // Parent organization (P749) and owned by (P127).
            'parent_ids' => array_values(array_unique([...$this->entityIds($claims, 'P749'), ...$this->entityIds($claims, 'P127')])) ?: null,
            'country_ids' => $this->entityIds($claims, 'P17') ?: null,
            'employees' => is_numeric($employees) ? (int) abs((float) $employees) : null,
        ];
    }

    /**
     * @param  array<mixed>  $claims
     * @return list<mixed>
     */
    private function values(array $claims, string $property, ?string $field = null): array
    {
        $values = [];

        foreach ((array) ($claims[$property] ?? []) as $claim) {
            $value = $claim['mainsnak']['datavalue']['value'] ?? null;
            $values[] = $field !== null && is_array($value) ? ($value[$field] ?? null) : $value;
        }

        return array_values(array_filter($values, fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<mixed>  $claims
     * @return list<string>
     */
    private function entityIds(array $claims, string $property): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null,
            $this->values($claims, $property),
        )));
    }

    private function text(mixed $value, int $length = 255): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $length) : null;
    }
}

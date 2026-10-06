<?php

namespace App\Identity;

use App\Sources\SourceRegistry;

/**
 * Decides which independence group a record's data belongs to.
 *
 * Usually that is the provider's group (Geoapify → osm_family). A record
 * that states its provenance can belong to another group: an Overture
 * place built only from AllThePlaces data is first-party store-locator
 * data, not independent confirmation of the AllThePlaces record.
 */
final class IndependenceResolver
{
    /**
     * @param  array<string, string>  $provenanceGroups  Lower-cased dataset name => group
     */
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly array $provenanceGroups,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $provenance  e.g. Overture "sources": [{dataset: "meta"}, ...]
     */
    public function groupFor(string $provider, array $provenance = []): string
    {
        $default = $this->sources->independenceGroup($provider);
        $groups = [];

        foreach ($provenance as $entry) {
            $dataset = is_array($entry) ? mb_strtolower(trim((string) ($entry['dataset'] ?? ''))) : '';

            // The provider's own entries (Overture's confidence calculation) add no origin.
            if ($dataset === '' || $dataset === mb_strtolower($provider)) {
                continue;
            }

            $groups[] = $this->provenanceGroups[$dataset] ?? $default;
        }

        $groups = array_values(array_unique($groups));

        // Only when every contributing dataset is the same other source.
        return count($groups) === 1 ? $groups[0] : $default;
    }
}

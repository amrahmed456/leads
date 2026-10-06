<?php

namespace App\Identity\Evidence;

use App\Enums\SourceRole;
use App\Models\BusinessSource;

/**
 * Where a set of evidence comes from.
 */
final readonly class EvidenceProvenance
{
    public function __construct(
        public string $provider,
        public SourceRole $role,
        public string $independenceGroup,
        public ?string $sourceRecordId = null,
        public ?string $sourceUrl = null,
        public ?int $businessSourceId = null,
    ) {}

    public static function fromSource(BusinessSource $source): self
    {
        return new self(
            provider: $source->provider,
            role: $source->source_role,
            independenceGroup: $source->independence_group,
            sourceRecordId: $source->provider_id,
            sourceUrl: $source->source_url,
            businessSourceId: $source->getKey(),
        );
    }
}

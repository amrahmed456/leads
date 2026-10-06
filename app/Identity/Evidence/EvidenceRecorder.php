<?php

namespace App\Identity\Evidence;

use App\Discovery\Data\DiscoveredBusiness;
use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Identity\NameSimilarity;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Support\Normalization\Domain;
use App\Support\Normalization\Text;

/**
 * Records facts attested by sources. Each (fact, provider, record, value)
 * is stored once and refreshed when seen again; values from different
 * sources are never overwritten, so conflicts keep their provenance.
 */
final class EvidenceRecorder
{
    /**
     * Every fact a discovery record states about its business.
     */
    public function recordDiscovered(Business $business, EvidenceProvenance $provenance, DiscoveredBusiness $data): void
    {
        $items = [
            new EvidenceItem(EvidenceFact::Existence, $data->name, $data->confidence),
            new EvidenceItem(EvidenceFact::Name, $data->name),
        ];

        $optional = [
            [EvidenceFact::Name, $data->alternateName, ['alternate' => true]],
            [EvidenceFact::Brand, $data->brand, []],
            [EvidenceFact::BrandWikidata, $data->brandWikidata, []],
            [EvidenceFact::Operator, $data->operator, []],
            [EvidenceFact::Phone, $data->phone, []],
            [EvidenceFact::Website, $data->website, []],
            [EvidenceFact::Email, $data->email, []],
            [EvidenceFact::Address, $data->address, []],
            [EvidenceFact::Category, $data->category?->value ?? $data->type, ['type' => $data->type]],
            [EvidenceFact::OperatingStatus, $data->operatingStatus, []],
            [EvidenceFact::OpeningHours, $data->openingHours, []],
        ];

        foreach ($optional as [$fact, $value, $metadata]) {
            if ($value !== null && $value !== '') {
                $items[] = new EvidenceItem($fact, $value, null, $metadata);
            }
        }

        if ($data->latitude !== null && $data->longitude !== null) {
            $items[] = new EvidenceItem(EvidenceFact::Coordinates, sprintf('%.6F,%.6F', $data->latitude, $data->longitude));
        }

        foreach ($data->socialLinks as $network => $url) {
            $items[] = new EvidenceItem(EvidenceFact::Social, $url, null, ['network' => $network]);
        }

        $this->recordMany($business, $provenance, $items);
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    public function recordMany(Business $business, EvidenceProvenance $provenance, array $items): void
    {
        $now = now();
        $rows = [];

        foreach ($items as $item) {
            $value = Text::clean($item->value, 500);
            $normalized = $value === null ? '' : self::normalize($item->fact, $value);

            if ($normalized === '') {
                continue;
            }

            $fingerprint = sha1(implode('|', [$item->fact->value, $provenance->provider, (string) $provenance->sourceRecordId, $normalized]));

            $rows[$fingerprint] = [
                'business_id' => $business->getKey(),
                'business_source_id' => $provenance->businessSourceId,
                'fact_type' => $item->fact->value,
                'value' => $value,
                'value_normalized' => $normalized,
                'provider' => $provenance->provider,
                'source_role' => $provenance->role->value,
                'independence_group' => $provenance->independenceGroup,
                'source_record_id' => $provenance->sourceRecordId,
                'source_url' => $provenance->sourceUrl !== null ? mb_substr($provenance->sourceUrl, 0, 500) : null,
                'confidence' => $item->confidence,
                'status' => EvidenceStatus::Active->value,
                'metadata' => $item->metadata === [] ? null : json_encode($item->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'observed_at' => $now,
                'last_seen_at' => $now,
                'fingerprint' => $fingerprint,
            ];
        }

        if ($rows === []) {
            return;
        }

        // observed_at keeps the first observation; everything else is refreshed.
        BusinessEvidence::query()->upsert(array_values($rows), ['business_id', 'fingerprint'], [
            'business_source_id', 'value', 'source_role', 'independence_group', 'source_url', 'confidence',
            'status', 'metadata', 'last_seen_at', 'updated_at',
        ]);
    }

    /**
     * Comparison form of a value, so the same fact from two sources matches.
     */
    public static function normalize(EvidenceFact $fact, string $value): string
    {
        $normalized = match ($fact) {
            EvidenceFact::Phone => substr((string) preg_replace('/\D+/', '', Text::asciiDigits($value)), -9),
            EvidenceFact::Website => (string) Domain::registrable($value),
            EvidenceFact::Email => mb_strtolower(trim($value)),
            EvidenceFact::BrandWikidata => strtoupper(trim($value)),
            EvidenceFact::Coordinates => self::coordinates($value),
            EvidenceFact::Social => mb_strtolower(rtrim((string) preg_replace('#^https?://(www\.)?#i', '', trim($value)), '/')),
            EvidenceFact::OperatingStatus, EvidenceFact::Category, EvidenceFact::CorporatePage => mb_strtolower(trim($value)),
            EvidenceFact::Brand, EvidenceFact::Operator, EvidenceFact::ParentOrganization, EvidenceFact::BranchOf => NameSimilarity::core($value) ?: Text::matchKey($value),
            default => Text::matchKey($value),
        };

        return mb_substr($normalized, 0, 191);
    }

    private static function coordinates(string $value): string
    {
        [$latitude, $longitude] = array_map('floatval', explode(',', $value) + [1 => 0]);

        return sprintf('%.4F,%.4F', $latitude, $longitude);
    }
}

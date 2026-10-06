<?php

namespace App\Identity\Evidence;

use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Enums\SourceRole;
use App\Enums\WebsiteSignal;
use App\Identity\NameSimilarity;
use App\Models\Business;
use App\Models\WebsiteAudit;
use App\Support\Normalization\Text;

/**
 * Turns a business's analyzed website into first-party evidence: what the
 * business says about itself (name, brand, parent organization, branch
 * relationship, contact details, official profiles, corporate pages).
 *
 * The website only confirms that the business exists when it clearly
 * belongs to it: its name or phone number matches.
 */
final class WebsiteEvidenceExtractor
{
    public const PROVIDER = 'website';

    public const GROUP = 'first_party_web';

    public function __construct(private readonly EvidenceRecorder $recorder) {}

    public function sync(Business $business, ?WebsiteAudit $audit): void
    {
        $current = $audit?->isAnalyzed() ? $audit->normalized_url : null;

        // Evidence from an earlier website (or one that no longer works) is stale.
        $business->evidence()
            ->where('provider', self::PROVIDER)
            ->when($current !== null, fn ($query) => $query->where('source_record_id', '!=', $current))
            ->update(['status' => EvidenceStatus::Stale->value]);

        if ($audit === null || $current === null) {
            return;
        }

        $provenance = new EvidenceProvenance(self::PROVIDER, SourceRole::Verification, self::GROUP, $current, $audit->final_url ?? $current);
        $this->recorder->recordMany($business, $provenance, $this->items($business, $audit));
    }

    /**
     * @return list<EvidenceItem>
     */
    private function items(Business $business, WebsiteAudit $audit): array
    {
        $items = [new EvidenceItem(EvidenceFact::Website, (string) $audit->normalized_url)];
        $names = [];
        $phones = $audit->signalData(WebsiteSignal::Phone)['numbers'] ?? [];

        foreach ($audit->structured_data['entities'] ?? [] as $entity) {
            $markup = ['schema_types' => $entity['types'] ?? [], 'format' => $entity['format'] ?? null];

            foreach ([
                'name' => EvidenceFact::Name, 'legal_name' => EvidenceFact::LegalName, 'brand' => EvidenceFact::Brand,
                'parent_organization' => EvidenceFact::ParentOrganization, 'branch_of' => EvidenceFact::BranchOf,
                'telephone' => EvidenceFact::Phone, 'email' => EvidenceFact::Email, 'address' => EvidenceFact::Address,
                'opening_hours' => EvidenceFact::OpeningHours,
            ] as $key => $fact) {
                if (is_string($entity[$key] ?? null)) {
                    $items[] = new EvidenceItem($fact, $entity[$key], null, $markup);
                }
            }

            if (isset($entity['latitude'], $entity['longitude'])) {
                $items[] = new EvidenceItem(EvidenceFact::Coordinates, sprintf('%.6F,%.6F', $entity['latitude'], $entity['longitude']), null, $markup);
            }

            foreach ($entity['same_as'] ?? [] as $url) {
                $items[] = new EvidenceItem(EvidenceFact::Social, $url, null, ['schema' => 'sameAs']);
            }

            array_push($names, ...array_filter([$entity['name'] ?? null, $entity['legal_name'] ?? null]));

            if (is_string($entity['telephone'] ?? null)) {
                $phones[] = $entity['telephone'];
            }
        }

        foreach ($audit->signalData(WebsiteSignal::Phone)['numbers'] ?? [] as $number) {
            $items[] = new EvidenceItem(EvidenceFact::Phone, $number);
        }

        foreach ($audit->signalData(WebsiteSignal::Email)['addresses'] ?? [] as $email) {
            $items[] = new EvidenceItem(EvidenceFact::Email, $email);
        }

        foreach ($audit->signalData(WebsiteSignal::SocialProfiles)['profiles'] ?? [] as $network => $url) {
            if (is_string($url)) {
                $items[] = new EvidenceItem(EvidenceFact::Social, $url, null, ['network' => $network]);
            }
        }

        foreach ($audit->corporate_signals ?? [] as $kind => $urls) {
            $items[] = new EvidenceItem(EvidenceFact::CorporatePage, $kind, null, ['urls' => $urls]);
        }

        $confirmation = $this->confirmation($business, [...$names, $audit->title], $phones);

        if ($confirmation !== null) {
            $items[] = new EvidenceItem(EvidenceFact::Existence, $business->name, 0.9, ['confirmed_by' => $confirmation]);
        }

        return $items;
    }

    /**
     * Why the website is the business's own, or null when that is unclear.
     *
     * @param  list<string|null>  $siteNames
     * @param  list<string>  $sitePhones
     */
    private function confirmation(Business $business, array $siteNames, array $sitePhones): ?string
    {
        foreach (array_filter([$business->name, $business->alternate_name]) as $name) {
            foreach (array_filter($siteNames) as $siteName) {
                if (NameSimilarity::score($name, $siteName) >= 0.75) {
                    return "The website names the business ({$siteName})";
                }
            }
        }

        $phone = $business->phone === null ? '' : substr((string) preg_replace('/\D+/', '', Text::asciiDigits($business->phone)), -9);

        foreach ($sitePhones as $sitePhone) {
            if (strlen($phone) >= 7 && substr((string) preg_replace('/\D+/', '', Text::asciiDigits($sitePhone)), -9) === $phone) {
                return "The website lists the business's phone number ({$sitePhone})";
            }
        }

        return null;
    }
}

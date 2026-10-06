<?php

namespace App\Identity;

use App\Enums\BrandScope;
use App\Enums\EntityType;
use App\Enums\EvidenceFact;
use App\Enums\ReasonCode;
use App\Identity\Brands\BrandAssessment;
use App\Identity\Brands\BrandClaim;
use App\Identity\Evidence\EvidenceRecorder;
use App\Identity\Evidence\ReconciliationResult;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Sources\SourceRegistry;
use App\Support\Normalization\Domain;
use App\Support\Normalization\Text;
use Illuminate\Support\Collection;

/**
 * Deterministic, explainable classification of what kind of entity a
 * business location belongs to.
 *
 * Rules run from the most to the least specific; the first decisive one
 * wins. Strong signals (a known brand ID, a first-party store locator, a
 * declared parent organization, a website shared by many branches) can
 * classify; weak signals (a resembling name, a shared phone) are only
 * recorded as hints and never classify a business on their own.
 */
final class EntityClassifier
{
    /**
     * @param  array<string, mixed>  $rules  config('intelligence.entity')
     */
    public function __construct(
        private readonly array $rules,
        private readonly BranchCounter $branches,
        private readonly SourceRegistry $sources,
    ) {}

    public function classify(Business $business, BrandAssessment $brand, ReconciliationResult $evidence): EntityClassification
    {
        $context = $this->context($business, $evidence);

        return $this->government($business, $context)
            ?? $this->marketplace($business, $context)
            ?? $this->enterprise($business, $brand)
            ?? $this->nonprofit($business, $context)
            ?? $this->branded($business, $brand, $context)
            ?? $this->organization($business, $context)
            ?? $this->repeatedDomain($context)
            ?? $this->independent($business, $brand, $context);
    }

    /**
     * @return array{tags: array<string, string>, domain: ?string, listing_domain: ?string, evidence: Collection<int, BusinessEvidence>}
     */
    private function context(Business $business, ReconciliationResult $evidence): array
    {
        $raw = $business->relationLoaded('sources')
            ? $business->sources->firstWhere('provider', 'openstreetmap')?->raw_data
            : $business->sources()->where('provider', 'openstreetmap')->value('raw_data');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        $domain = Domain::registrable($business->website);
        $shared = [...array_keys((array) $this->rules['aggregators']), ...array_keys((array) $this->rules['directories'])];

        return [
            'tags' => is_array($raw['tags'] ?? null) ? $raw['tags'] : [],
            // A marketplace listing or social profile is not the business's own domain.
            'domain' => $domain !== null && Domain::matchesAny($domain, $shared) === null ? $domain : null,
            'listing_domain' => $domain,
            'evidence' => $evidence->evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function government(Business $business, array $context): ?EntityClassification
    {
        $rules = $this->rules['government'];
        $suffix = Domain::matchesAny($context['listing_domain'], $rules['domain_suffixes']);

        if ($suffix !== null) {
            return new EntityClassification(EntityType::Government, 95, [ReasonCode::Government->reason("Government website domain ({$context['listing_domain']})")]);
        }

        foreach ($rules['osm_tags'] as $key => $values) {
            if (in_array($context['tags'][$key] ?? null, $values, true)) {
                return new EntityClassification(EntityType::Government, 90, [ReasonCode::Government->reason("Mapped as {$key}={$context['tags'][$key]}")]);
            }
        }

        $keyword = $this->keywordIn($business, $rules['name_keywords']);

        return $keyword === null ? null : new EntityClassification(EntityType::Government, 75, [ReasonCode::Government->reason("The name contains \"{$keyword}\"")]);
    }

    /**
     * The business itself is a marketplace or listing site (not a business that uses one).
     *
     * @param  array<string, mixed>  $context
     */
    private function marketplace(Business $business, array $context): ?EntityClassification
    {
        foreach (['aggregators' => [EntityType::Aggregator, ReasonCode::Aggregator], 'directories' => [EntityType::Directory, ReasonCode::Directory]] as $list => [$type, $code]) {
            $domain = Domain::matchesAny($context['listing_domain'], array_keys((array) $this->rules[$list]));

            if ($domain !== null && NameSimilarity::score($business->name, $this->rules[$list][$domain]) >= 0.8) {
                return new EntityClassification($type, 90, [$code->reason("{$this->rules[$list][$domain]} is a {$code->label()}")]);
            }
        }

        return null;
    }

    private function enterprise(Business $business, BrandAssessment $brand): ?EntityClassification
    {
        if ($brand->hasBrand() && $brand->brand->entity_type === EntityType::Enterprise) {
            return new EntityClassification(EntityType::Enterprise, min(98, $brand->confidence + 10), [
                ReasonCode::Enterprise->reason("{$brand->brand->name} is a large enterprise (bank, telecom, insurance or energy brand)"),
                ReasonCode::KnownBrand->reason($this->brandReason($brand)),
            ]);
        }

        $keyword = $this->keywordIn($business, $this->rules['enterprise']['name_keywords']);

        // A name like "... Bank" alone is not certain enough to disqualify.
        return $keyword === null ? null : new EntityClassification(EntityType::Enterprise, 65, [
            ReasonCode::Enterprise->reason("The name contains \"{$keyword}\", typical of large enterprises"),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function nonprofit(Business $business, array $context): ?EntityClassification
    {
        foreach ($this->rules['nonprofit']['osm_tags'] as $key => $values) {
            if (in_array($context['tags'][$key] ?? null, $values, true)) {
                return new EntityClassification(EntityType::Nonprofit, 85, [ReasonCode::Nonprofit->reason("Mapped as {$key}={$context['tags'][$key]}")]);
            }
        }

        $keyword = $this->keywordIn($business, $this->rules['nonprofit']['name_keywords']);

        return $keyword === null ? null : new EntityClassification(EntityType::Nonprofit, 65, [ReasonCode::Nonprofit->reason("The name contains \"{$keyword}\"")]);
    }

    /**
     * A branch of a recognized brand.
     *
     * @param  array<string, mixed>  $context
     */
    private function branded(Business $business, BrandAssessment $brand, array $context): ?EntityClassification
    {
        if (! $brand->hasBrand()) {
            return null;
        }

        $model = $brand->brand;
        $counts = $this->branches->forBrand($model);
        $reasons = [ReasonCode::KnownBrand->reason($this->brandReason($brand))];
        $confidence = $brand->confidence;

        if ($brand->hasFirstPartyClaim()) {
            $reasons[] = ReasonCode::FirstPartyChainSource->reason("Listed in {$model->name}'s official store locator (AllThePlaces)");
            $confidence += 5;
        }

        if ($counts['locations'] >= 2) {
            $reasons[] = ReasonCode::MultipleBranches->reason(sprintf('%s locations known%s', number_format($counts['locations']), $counts['cities'] > 1 ? " in {$counts['cities']} cities" : ''));
            // A verified large branch network corroborates even a name-only brand match.
            $confidence += $counts['locations'] >= $this->rules['branches']['chain_min_locations'] ? 10 : 5;
        }

        if ($context['domain'] !== null && in_array($context['domain'], $model->official_domains ?? [], true)) {
            $reasons[] = ReasonCode::CorporateDomain->reason("The website {$context['domain']} is the brand's official website");
            $confidence += 5;
        }

        $branchRules = $this->rules['branches'];
        $operator = $this->value($context['evidence'], EvidenceFact::Operator);
        $parent = $this->value($context['evidence'], EvidenceFact::ParentOrganization);
        $franchisePage = $this->hasCorporatePage($context['evidence'], 'franchise');

        [$type, $code, $label] = match (true) {
            $model->scope === BrandScope::International => [EntityType::InternationalChain, ReasonCode::InternationalChain, "{$model->name} operates in several countries"],
            $model->scope === BrandScope::National => [EntityType::NationalChain, ReasonCode::NationalChain, "{$model->name} operates nationally"],
            $counts['locations'] >= $branchRules['chain_min_locations'] && $counts['cities'] >= $branchRules['national_min_cities'] => [EntityType::NationalChain, ReasonCode::NationalChain, "{$counts['locations']} locations in {$counts['cities']} cities"],
            $model->scope === BrandScope::Regional || $counts['locations'] >= $branchRules['chain_min_locations'] => [EntityType::RegionalChain, ReasonCode::RegionalChain, "{$model->name} has {$counts['locations']} locations in the region"],
            // A declared parent group outweighs a franchise offer: this is the group's own brand.
            $parent !== null => [EntityType::Corporate, ReasonCode::ParentOrganization, "The website names {$parent} as its parent organization"],
            $franchisePage || ($operator !== null && NameSimilarity::score($operator, $model->name) < 0.5) => [EntityType::Franchise, ReasonCode::Franchise, $franchisePage ? 'The website offers franchises' : "Operated by {$operator} under the {$model->name} brand"],
            $counts['locations'] >= $branchRules['local_multi_branch_min'] => [EntityType::LocalMultiBranch, ReasonCode::MultipleBranches, "{$counts['locations']} locations of a local brand"],
            default => [null, null, null],
        };

        if ($type === null) {
            // A brand tag on a single known location is usually the business's own name.
            return new EntityClassification(EntityType::LocalBusiness, 55, [
                ReasonCode::SingleLocation->reason("Brand {$model->name} has only this known location"),
                [...ReasonCode::KnownBrand->reason($this->brandReason($brand)), 'weak' => true],
            ]);
        }

        array_unshift($reasons, $code->reason($label));

        return new EntityClassification($type, min(98, $confidence), $this->unique($reasons));
    }

    /**
     * The website declares a parent organization or a branch relationship.
     *
     * @param  array<string, mixed>  $context
     */
    private function organization(Business $business, array $context): ?EntityClassification
    {
        $parent = $this->value($context['evidence'], EvidenceFact::ParentOrganization);
        $branchOf = $this->value($context['evidence'], EvidenceFact::BranchOf);

        if ($parent === null && $branchOf === null) {
            return null;
        }

        $reasons = [];
        $counts = $context['domain'] !== null ? $this->branches->sharing(EvidenceFact::Website, $context['domain']) : ['locations' => 1, 'cities' => 1];

        if ($parent !== null) {
            $reasons[] = ReasonCode::ParentOrganization->reason("The website names {$parent} as its parent organization");
        }

        if ($branchOf !== null) {
            $reasons[] = ReasonCode::BranchOf->reason("The website declares it is a branch of {$branchOf}");
        }

        if ($counts['locations'] >= $this->rules['branches']['chain_min_locations'] && $counts['cities'] >= $this->rules['branches']['national_min_cities']) {
            $reasons[] = ReasonCode::MultipleBranches->reason("{$counts['locations']} locations share the website in {$counts['cities']} cities");

            return new EntityClassification(EntityType::NationalChain, 80, $reasons);
        }

        if ($parent === null) {
            return new EntityClassification(EntityType::LocalMultiBranch, 65, $reasons);
        }

        $investors = $this->hasCorporatePage($context['evidence'], 'investors');

        if ($investors) {
            $reasons[] = ReasonCode::CorporatePages->reason('The website has investor relations pages');
        }

        // A declared parent alone may be a small local group: review unless corroborated.
        return new EntityClassification(EntityType::Corporate, $investors || $counts['locations'] >= 2 ? 80 : 65, $reasons);
    }

    /**
     * Several locations share the business's own website: a multi-branch business or chain.
     *
     * @param  array<string, mixed>  $context
     */
    private function repeatedDomain(array $context): ?EntityClassification
    {
        if ($context['domain'] === null) {
            return null;
        }

        $counts = $this->branches->sharing(EvidenceFact::Website, $context['domain']);
        $rules = $this->rules['branches'];

        if ($counts['locations'] >= $rules['chain_min_locations'] && $counts['cities'] >= $rules['national_min_cities']) {
            return new EntityClassification(EntityType::NationalChain, 75, [
                ReasonCode::RepeatedDomain->reason("{$counts['locations']} locations in {$counts['cities']} cities share {$context['domain']}"),
                ReasonCode::MultipleBranches->reason("{$counts['locations']} branches"),
            ]);
        }

        if ($counts['locations'] >= $rules['repeated_domain_min'] || ($counts['locations'] >= $rules['local_multi_branch_min'] && $counts['cities'] <= 1)) {
            return new EntityClassification(EntityType::LocalMultiBranch, 70, [
                ReasonCode::MultipleBranches->reason("{$counts['locations']} locations share the website {$context['domain']}"),
            ]);
        }

        return null;
    }

    /**
     * No chain or organization evidence: an independent or local business,
     * with any weak hints recorded for review.
     *
     * @param  array<string, mixed>  $context
     */
    private function independent(Business $business, BrandAssessment $brand, array $context): EntityClassification
    {
        $weak = [];

        foreach ($brand->possibleBrands as $possible) {
            $weak[] = [...ReasonCode::PossibleBrandMatch->reason("The name resembles the brand {$possible}"), 'weak' => true];
        }

        foreach ($brand->claims as $claim) {
            if ($claim->strength === BrandClaim::WEAK) {
                $weak[] = [...ReasonCode::PossibleBrandMatch->reason($claim->reason), 'weak' => true];
            }
        }

        $phone = $business->phone !== null ? EvidenceRecorder::normalize(EvidenceFact::Phone, $business->phone) : '';
        if (strlen($phone) >= 7 && ($shared = $this->branches->sharing(EvidenceFact::Phone, $phone))['locations'] >= $this->rules['branches']['shared_phone_min']) {
            $weak[] = [...ReasonCode::SharedPhone->reason("{$shared['locations']} locations share the phone number"), 'weak' => true];
        }

        foreach ($context['evidence']->where('fact_type', EvidenceFact::Social)->pluck('value_normalized')->unique()->take(5) as $profile) {
            if (($shared = $this->branches->sharing(EvidenceFact::Social, $profile))['locations'] >= $this->rules['branches']['shared_phone_min']) {
                $weak[] = [...ReasonCode::SharedSocialAccount->reason("{$shared['locations']} locations share {$profile}"), 'weak' => true];

                break;
            }
        }

        $sameName = $this->branches->sameName($business);
        if ($sameName['locations'] >= $this->rules['branches']['repeated_domain_min'] && $sameName['cities'] >= 2) {
            $weak[] = [...ReasonCode::MultipleBranches->reason("{$sameName['locations']} places share this name in {$sameName['cities']} cities"), 'weak' => true];
        }

        if ($this->keywordIn($business, $this->rules['corporate_name_keywords']) !== null) {
            $weak[] = [...ReasonCode::Corporate->reason('The name suggests a corporate group'), 'weak' => true];
        }

        if ($business->category === null && $business->type === null) {
            return new EntityClassification(EntityType::Unknown, 30, [ReasonCode::UnknownEntity->reason('No category or business type is known'), ...$weak]);
        }

        if ($context['domain'] !== null) {
            return new EntityClassification(EntityType::Independent, $weak === [] ? 75 : 60, [
                ReasonCode::OwnWebsite->reason("Has its own website ({$context['domain']}) not shared with other locations"),
                ReasonCode::IndependentBusiness->reason('No brand, chain or parent organization evidence'),
                ...$weak,
            ]);
        }

        return new EntityClassification(EntityType::LocalBusiness, $weak === [] ? 60 : 50, [
            ReasonCode::SingleLocation->reason('A single location with no brand, chain or parent organization evidence'),
            ...$weak,
        ]);
    }

    private function brandReason(BrandAssessment $brand): string
    {
        $sources = implode(', ', array_map($this->sources->label(...), $brand->providers()));

        return "{$brand->brand?->name} confirmed by {$sources}".($brand->brand?->wikidata_id ? " (Wikidata {$brand->brand->wikidata_id})" : '');
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $evidence
     */
    private function value(Collection $evidence, EvidenceFact $fact): ?string
    {
        return $evidence->firstWhere('fact_type', $fact)?->value;
    }

    /**
     * @param  Collection<int, BusinessEvidence>  $evidence
     */
    private function hasCorporatePage(Collection $evidence, string $kind): bool
    {
        return $evidence->contains(fn (BusinessEvidence $item): bool => $item->fact_type === EvidenceFact::CorporatePage && $item->value_normalized === $kind);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function keywordIn(Business $business, array $keywords): ?string
    {
        $names = ' '.Text::searchable(implode(' ', array_filter([$business->name, $business->alternate_name]))).' ';

        foreach ($keywords as $keyword) {
            $needle = Text::searchable($keyword);

            if ($needle !== '' && str_contains($names, " {$needle} ")) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * @param  list<array{code: string, label: string, weak?: bool}>  $reasons
     * @return list<array{code: string, label: string, weak?: bool}>
     */
    private function unique(array $reasons): array
    {
        $seen = [];

        return array_values(array_filter($reasons, function (array $reason) use (&$seen): bool {
            $key = $reason['code'].'|'.$reason['label'];

            return ! isset($seen[$key]) && ($seen[$key] = true);
        }));
    }
}

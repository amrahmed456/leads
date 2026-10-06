<?php

namespace App\Intelligence\Eligibility;

use App\Enums\EntityType;
use App\Enums\LeadStatus;
use App\Enums\ReasonCode;
use App\Intelligence\Opportunities\DetectedOpportunity;

/**
 * Decides, deterministically and with reasons, whether a business is worth
 * contacting: HOT, WARM, LOW, REVIEW_REQUIRED or DISQUALIFIED.
 *
 * Order of decisions:
 *  1. Hard disqualifiers: closed; recognized chain, enterprise, government,
 *     corporate or marketplace (when the entity confidence is high enough;
 *     otherwise review); an excellent digital presence with nothing to sell.
 *  2. Treatments for franchises, regional chains and non-profits.
 *  3. HOT only when every requirement holds (eligible entity, confidence,
 *     fit, opportunity, independent evidence, no identity conflict, a
 *     contact route, a service we sell, no chain hints).
 *  4. Doubt (unknown entity, identity conflict, chain hints) on an otherwise
 *     promising business → REVIEW_REQUIRED, never HOT.
 *  5. WARM when the lower bar is met; LOW otherwise.
 *
 * Being found by a provider, or by many providers, never makes a lead.
 */
final class LeadEligibilityService
{
    /**
     * @param  array<string, mixed>  $rules  config('intelligence.eligibility')
     * @param  list<string>  $servicesOffered  OpportunityType values
     * @param  array{opportunity: float, business_fit: float, data_confidence: float}  $weights
     */
    public function __construct(
        private readonly array $rules,
        private readonly int $disqualifyMinEntityConfidence,
        private readonly array $servicesOffered,
        private readonly array $weights,
    ) {}

    public function evaluate(EligibilityInput $input): EligibilityResult
    {
        [$status, $reasons] = $this->decide($input);

        return $this->result($status, $reasons, $input);
    }

    /**
     * @return array{0: LeadStatus, 1: list<array{code: string, label: string}>}
     */
    private function decide(EligibilityInput $input): array
    {
        $entity = $input->entity;
        $type = $entity->type;
        $decisive = $this->decisiveReasons($entity->reasons);

        if ($input->business->operating_status === 'permanently_closed') {
            return [LeadStatus::Disqualified, [ReasonCode::PermanentlyClosed->reason('A source reports the business as permanently closed')]];
        }

        if (in_array($type->value, $this->rules['disqualified_entity_types'], true)) {
            if ($entity->confidence >= $this->disqualifyMinEntityConfidence) {
                return [LeadStatus::Disqualified, $decisive];
            }

            return [LeadStatus::ReviewRequired, [
                ReasonCode::UnknownEntity->reason("Possibly {$type->label()} ({$entity->confidence}% sure): check before contacting"),
                ...$decisive,
            ]];
        }

        $treatment = match ($type) {
            EntityType::Franchise => $this->rules['franchise_treatment'],
            EntityType::RegionalChain => $this->rules['regional_chain_treatment'],
            EntityType::Nonprofit => $this->rules['nonprofit_treatment'],
            default => null,
        };

        if ($treatment === 'disqualify') {
            return [LeadStatus::Disqualified, $decisive];
        }

        if ($input->opportunity->has('strong_digital_presence') && $input->opportunity->score <= $this->rules['strong_presence_max_opportunity']) {
            return [LeadStatus::Disqualified, [
                ReasonCode::StrongDigitalPresence->reason('Already has a strong digital presence; none of our services fills a clear gap'),
            ]];
        }

        $hot = $this->rules['hot'];
        $warm = $this->rules['warm'];
        $confidence = $input->dataConfidence->score;
        $fit = $input->businessFit->score;
        $opportunity = $input->opportunity->score;
        $groups = count($input->evidence->existenceGroups);
        $sellable = $this->sellable($input->opportunities);
        $eligibleType = in_array($type->value, $this->rules['eligible_entity_types'], true);

        // Requirements whose failure is a matter of doubt rather than of value.
        $doubts = array_values(array_filter([
            $type === EntityType::Unknown ? ReasonCode::UnknownEntity->reason('The kind of business could not be determined') : null,
            $input->evidence->hasMajorConflict() ? ReasonCode::ConflictingIdentity->reason('Sources disagree on who this business is') : null,
            ...array_map(fn (array $reason): array => ['code' => $reason['code'], 'label' => 'Chain hint: '.$reason['label']], $entity->weakSignals()),
            $treatment === 'review' ? [
                'code' => $decisive[0]['code'] ?? ReasonCode::Franchise->value,
                'label' => "{$type->label()}: may be run locally or decided centrally; check before contacting",
            ] : null,
        ]));

        // Requirements whose failure means the lead is weaker.
        $gaps = array_values(array_filter([
            $confidence < $hot['min_data_confidence'] ? ReasonCode::LowDataConfidence->reason("Data confidence {$confidence} (HOT needs {$hot['min_data_confidence']})") : null,
            $fit < $hot['min_business_fit'] ? ReasonCode::LowBusinessFit->reason("Business fit {$fit} (HOT needs {$hot['min_business_fit']})") : null,
            $opportunity < $hot['min_opportunity'] ? ReasonCode::LowOpportunity->reason("Opportunity {$opportunity} (HOT needs {$hot['min_opportunity']})") : null,
            $groups < $hot['min_evidence_groups'] ? ReasonCode::InsufficientEvidence->reason("{$groups} independent source group(s) confirm it (HOT needs {$hot['min_evidence_groups']})") : null,
            $input->contactRoutes === [] ? ReasonCode::NoContactRoute->reason('No phone, WhatsApp, e-mail, website or social profile') : null,
            $sellable === [] ? ReasonCode::NoSellableOpportunity->reason('No detected opportunity matches a service we offer') : null,
        ]));

        $warmOk = $confidence >= $warm['min_data_confidence'] && $fit >= $warm['min_business_fit']
            && $opportunity >= $warm['min_opportunity'] && $input->contactRoutes !== [] && $sellable !== [];

        if ($doubts === [] && $gaps === [] && $eligibleType) {
            return [LeadStatus::Hot, [
                ReasonCode::MeetsHotCriteria->reason(sprintf(
                    '%s; confidence %d, fit %d, opportunity %d; confirmed by %d independent source groups',
                    $type->label(), $confidence, $fit, $opportunity, $groups,
                )),
                ...$this->opportunityReasons($sellable),
                ...$decisive,
            ]];
        }

        if ($doubts !== [] && ($warmOk || $gaps === [])) {
            return [LeadStatus::ReviewRequired, [
                ...$doubts,
                ...$gaps,
                ...($treatment !== null ? $decisive : []),
                ...$this->opportunityReasons($sellable),
            ]];
        }

        if ($warmOk && ($eligibleType || $treatment === 'warm')) {
            return [LeadStatus::Warm, [
                ReasonCode::MeetsWarmCriteria->reason("Confidence {$confidence}, fit {$fit}, opportunity {$opportunity}"),
                ...$gaps,
                ...($treatment === 'warm' ? $decisive : []),
                ...$this->opportunityReasons($sellable),
            ]];
        }

        return [LeadStatus::Low, [...$doubts, ...$gaps]];
    }

    /**
     * @param  list<array{code: string, label: string}>  $reasons
     */
    private function result(LeadStatus $status, array $reasons, EligibilityInput $input): EligibilityResult
    {
        $breakdown = [
            ['key' => 'opportunity', 'label' => "Opportunity {$input->opportunity->score} × {$this->percent('opportunity')}", 'points' => (int) round($input->opportunity->score * $this->weights['opportunity'])],
            ['key' => 'business_fit', 'label' => "Business fit {$input->businessFit->score} × {$this->percent('business_fit')}", 'points' => (int) round($input->businessFit->score * $this->weights['business_fit'])],
            ['key' => 'data_confidence', 'label' => "Data confidence {$input->dataConfidence->score} × {$this->percent('data_confidence')}", 'points' => (int) round($input->dataConfidence->score * $this->weights['data_confidence'])],
        ];

        $score = max(0, min(100, array_sum(array_column($breakdown, 'points'))));

        if ($status === LeadStatus::Disqualified) {
            $breakdown[] = ['key' => 'disqualified', 'label' => 'Disqualified: not a prospect', 'points' => -$score];
            $score = 0;
        }

        $unique = [];
        foreach ($reasons as $reason) {
            $unique[$reason['code'].'|'.$reason['label']] ??= ['code' => $reason['code'], 'label' => $reason['label']];
        }

        return new EligibilityResult($status, array_values($unique), $score, $breakdown);
    }

    /**
     * Decisive (non-weak) classification reasons, as stored reasons.
     *
     * @param  list<array{code: string, label: string, weak?: bool}>  $reasons
     * @return list<array{code: string, label: string}>
     */
    private function decisiveReasons(array $reasons): array
    {
        return array_values(array_map(
            fn (array $reason): array => ['code' => $reason['code'], 'label' => $reason['label']],
            array_filter($reasons, fn (array $reason): bool => ! ($reason['weak'] ?? false)),
        ));
    }

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     * @return list<DetectedOpportunity>
     */
    private function sellable(array $opportunities): array
    {
        return array_values(array_filter(
            $opportunities,
            fn (DetectedOpportunity $opportunity): bool => in_array($opportunity->type->value, $this->servicesOffered, true),
        ));
    }

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     * @return list<array{code: string, label: string}>
     */
    private function opportunityReasons(array $opportunities): array
    {
        return array_map(fn (DetectedOpportunity $opportunity): array => [
            'code' => 'OPPORTUNITY_'.strtoupper($opportunity->type->value),
            'label' => "Opportunity: {$opportunity->type->label()} ({$opportunity->priority->value} priority)",
        ], array_slice($opportunities, 0, 3));
    }

    private function percent(string $weight): string
    {
        return round($this->weights[$weight] * 100).'%';
    }
}

<?php

namespace App\Intelligence\Scoring;

use App\Enums\EntityType;
use App\Models\Business;

/**
 * "Is this type of business a good target for our services?" — a category
 * base plus an adjustment for the kind of entity (config/intelligence.php
 * "business_fit"). Chains, enterprises and government get large penalties.
 */
final class BusinessFitService
{
    /**
     * @param  array{category_points: array<string, int>, categories: array<string, list<string>>, entity_adjustments: array<string, int>}  $rules
     */
    public function __construct(private readonly array $rules) {}

    public function score(Business $business): ScoreResult
    {
        $category = $business->category;
        $tier = 'other';

        foreach ($this->rules['categories'] as $candidate => $categories) {
            if ($category !== null && in_array($category->value, $categories, true)) {
                $tier = $candidate;

                break;
            }
        }

        $label = match ($tier) {
            'high' => "Strong-fit category: {$category?->label()}",
            'medium' => "Good-fit category: {$category?->label()}",
            'low' => "Weak-fit category: {$category?->label()}",
            default => $category !== null ? "Category: {$category->label()}" : 'Category unknown',
        };

        $items = [['key' => "category_{$tier}", 'label' => $label, 'points' => (int) $this->rules['category_points'][$tier]]];

        $type = $business->entity_type ?? EntityType::Unknown;
        $adjustment = (int) ($this->rules['entity_adjustments'][$type->value] ?? 0);

        if ($adjustment !== 0) {
            $items[] = [
                'key' => "entity_{$type->value}",
                'label' => $adjustment > 0 ? "{$type->label()} businesses decide for themselves" : "{$type->label()}: decisions are made centrally or elsewhere",
                'points' => $adjustment,
            ];
        }

        return ScoreResult::fromBreakdown($items);
    }
}

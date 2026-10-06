<?php

namespace App\Identity\Brands;

use App\Models\Brand;

/**
 * What is known about the brand of a business.
 */
final readonly class BrandAssessment
{
    /**
     * @param  Brand|null  $brand  Set only for strong or medium evidence
     * @param  int  $strength  BrandClaim::STRONG / MEDIUM / WEAK, or 0 for no brand evidence
     * @param  int  $confidence  0-100
     * @param  list<BrandClaim>  $claims  Every claim, strongest first
     * @param  list<string>  $possibleBrands  Brand names merely resembled by the business name
     */
    public function __construct(
        public ?Brand $brand,
        public int $strength,
        public int $confidence,
        public array $claims,
        public array $possibleBrands = [],
    ) {}

    public static function none(): self
    {
        return new self(null, 0, 0, []);
    }

    public function hasBrand(): bool
    {
        return $this->brand !== null && $this->strength >= BrandClaim::MEDIUM;
    }

    public function isStrong(): bool
    {
        return $this->brand !== null && $this->strength >= BrandClaim::STRONG;
    }

    public function hasFirstPartyClaim(): bool
    {
        foreach ($this->claims as $claim) {
            if ($claim->provider === 'alltheplaces') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_unique(array_map(fn (BrandClaim $claim): string => $claim->provider, $this->claims)));
    }
}

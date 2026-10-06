<?php

namespace App\Identity\Brands;

/**
 * A source's statement that a business belongs to a brand.
 */
final readonly class BrandClaim
{
    public const STRONG = 3;

    public const MEDIUM = 2;

    public const WEAK = 1;

    public function __construct(
        public string $name,
        public ?string $wikidataId,
        public string $provider,
        public string $independenceGroup,
        public int $strength,
        public string $reason,
    ) {}

    public function strengthLabel(): string
    {
        return match ($this->strength) {
            self::STRONG => 'strong',
            self::MEDIUM => 'medium',
            default => 'weak',
        };
    }
}

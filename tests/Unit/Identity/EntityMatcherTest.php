<?php

namespace Tests\Unit\Identity;

use App\Identity\EntityMatcher;
use App\Identity\MatchCandidate;
use App\Identity\NameSimilarity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EntityMatcherTest extends TestCase
{
    public function test_a_shared_strong_identifier_matches_regardless_of_name_or_distance(): void
    {
        $match = $this->matcher()->match(
            $this->candidate('Smile Dental', 24.70, 46.70, identifiers: ['openstreetmap' => ['node/42']]),
            $this->candidate('Completely Different', 25.00, 47.00, identifiers: ['geoapify' => ['g1'], 'openstreetmap' => ['node/42']]),
        );

        $this->assertTrue($match->matched);
        $this->assertSame(100, $match->confidence);
    }

    public function test_the_same_name_nearby_is_the_same_place(): void
    {
        $match = $this->matcher()->match($this->candidate('Al Reef'), $this->candidate('AL-REEF', 24.70030));

        $this->assertTrue($match->matched);
        $this->assertSame(90, $match->confidence);
    }

    public function test_the_same_name_far_away_is_a_different_branch(): void
    {
        $this->assertFalse($this->matcher()->match($this->candidate('Kudu'), $this->candidate('Kudu', 24.709))->matched);
    }

    public function test_a_shared_phone_alone_never_merges(): void
    {
        $match = $this->matcher()->match(
            $this->candidate('Olaya Shawarma', phone: '+966920000000'),
            $this->candidate('Tahlia Burgers', 24.70040, phone: '920000000'),
        );

        $this->assertFalse($match->matched);
        $this->assertStringContainsString('which alone is not enough', $match->reasons[1]);
    }

    public function test_a_similar_name_with_the_same_phone_merges(): void
    {
        $match = $this->matcher()->match(
            $this->candidate('ABC Dental', phone: '+966112003000'),
            $this->candidate('ABC Dental Group', 24.70080, phone: '0112003000'),
        );

        $this->assertTrue($match->matched);
        $this->assertSame(['Similar name (80%) 89 m apart', 'Same phone'], $match->reasons);
    }

    public function test_a_shared_social_profile_is_not_a_shared_website(): void
    {
        $match = $this->matcher()->match(
            $this->candidate('ABC Dental', website: 'https://instagram.com/abc'),
            $this->candidate('ABC Dental Group', 24.70080, website: 'https://www.instagram.com/abc'),
        );

        $this->assertFalse($match->matched);
    }

    public function test_a_store_locator_branch_of_the_same_brand_matches_a_branch_tagged_with_the_brand(): void
    {
        $match = $this->matcher()->match(
            $this->candidate('KFC', brand: 'KFC', brandWikidata: 'Q524757'),
            $this->candidate('KFC King Fahd Road', 24.70050, brand: 'KFC', brandWikidata: 'Q524757'),
        );

        $this->assertTrue($match->matched);
    }

    public function test_records_without_a_location_never_match_on_name(): void
    {
        $this->assertFalse($this->matcher()->match($this->candidate('Kudu'), $this->candidate('Kudu', null))->matched);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function names(): array
    {
        return [
            'identical after normalization' => ['Al-Reef', 'al reef', 1.0],
            'branch suffix' => ['KFC - King Fahd Road', 'KFC', 0.95],
            'generic words' => ['Al Reef Restaurant', 'مطعم Al Reef', 0.95],
            'spacing' => ['Al Baik', 'AlBaik', 0.95],
            'Arabic letter variants' => ['مؤسسة الريف', 'موسسه الريف', 1.0],
            'contained name' => ['Burger Spot', 'Burger Spot Olaya', 0.8],
            'unrelated' => ['Kudu', 'Hardees', 0.0],
        ];
    }

    #[DataProvider('names')]
    public function test_name_similarity(string $a, string $b, float $minimum): void
    {
        $score = NameSimilarity::score($a, $b);

        $minimum === 0.0
            ? $this->assertLessThan(0.5, $score)
            : $this->assertGreaterThanOrEqual($minimum, $score);
    }

    private function matcher(): EntityMatcher
    {
        return new EntityMatcher([
            'max_distance_meters' => 150, 'same_name_distance_meters' => 75, 'same_name_similarity' => 0.9,
            'similar_name' => 0.75, 'same_brand_distance_meters' => 100, 'same_brand_name_similarity' => 0.5,
            'adjacent_distance_meters' => 40,
        ], ['talabat.com']);
    }

    /**
     * @param  array<string, list<string>>  $identifiers
     */
    private function candidate(string $name, ?float $latitude = 24.70, ?string $phone = null, ?string $website = null, ?string $brand = null, ?string $brandWikidata = null, array $identifiers = []): MatchCandidate
    {
        return new MatchCandidate([$name], $latitude, $latitude === null ? null : 46.70, $phone, $website, $brand, $brandWikidata, $identifiers);
    }
}

<?php

namespace App\Identity;

use App\Support\Geo;
use App\Support\Normalization\Domain;
use App\Support\Normalization\Text;

/**
 * Decides whether two records describe the same physical business.
 *
 * A merge needs a strong identifier (the same record in another source) or
 * a nearby location plus a name that is practically identical, or similar
 * and backed by a second identifier (phone, own website, brand). The same
 * name far away, or a shared phone alone, never merges: chains and
 * unrelated businesses share names and central numbers.
 */
final class EntityMatcher
{
    /** Social networks and link pages: a shared profile never identifies a business. */
    private const SOCIAL_DOMAINS = [
        'facebook.com', 'fb.com', 'instagram.com', 'instagr.am', 'x.com', 'twitter.com', 'tiktok.com', 'snapchat.com',
        'linkedin.com', 'youtube.com', 'youtu.be', 'wa.me', 'whatsapp.com', 't.me', 'telegram.me', 'linktr.ee',
        'google.com', 'goo.gl', 'business.site',
    ];

    /**
     * @param  array{max_distance_meters: int, same_name_distance_meters: int, same_name_similarity: float, similar_name: float, same_brand_distance_meters: int, same_brand_name_similarity: float, adjacent_distance_meters: int}  $rules
     * @param  list<string>  $sharedDomains  Marketplace / directory domains that never identify a business
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $sharedDomains = [],
    ) {}

    public function maxDistance(): int
    {
        return (int) $this->rules['max_distance_meters'];
    }

    public function match(MatchCandidate $known, MatchCandidate $candidate): MatchResult
    {
        $identifier = $this->sharedIdentifier($known, $candidate);

        if ($identifier !== null) {
            return new MatchResult(true, 100, ["Same {$identifier} record"]);
        }

        if ($known->latitude === null || $known->longitude === null || $candidate->latitude === null || $candidate->longitude === null) {
            return MatchResult::noMatch(['No location to compare']);
        }

        $distance = Geo::distanceMeters($known->latitude, $known->longitude, $candidate->latitude, $candidate->longitude);
        $similarity = $this->nameSimilarity($known, $candidate);
        $meters = (int) round($distance);

        if ($distance > $this->rules['max_distance_meters']) {
            return MatchResult::noMatch(["{$meters} m apart"], $distance, $similarity);
        }

        $shared = array_keys(array_filter([
            'phone' => $this->samePhone($known->phone, $candidate->phone),
            'website' => $this->sameWebsite($known->website, $candidate->website),
            'brand' => $this->sameBrand($known, $candidate),
        ]));
        $percent = (int) round($similarity * 100);

        if ($distance <= $this->rules['same_name_distance_meters'] && $similarity >= $this->rules['same_name_similarity']) {
            return new MatchResult(true, 90, ["Same name ({$percent}% similar) {$meters} m apart"], $distance, $similarity);
        }

        if ($similarity >= $this->rules['similar_name'] && $shared !== []) {
            return new MatchResult(true, 85, [
                "Similar name ({$percent}%) {$meters} m apart",
                'Same '.implode(' and ', $shared),
            ], $distance, $similarity);
        }

        if (in_array('brand', $shared, true) && $distance <= $this->rules['same_brand_distance_meters']
            && $similarity >= $this->rules['same_brand_name_similarity']) {
            return new MatchResult(true, 80, ["Same brand {$meters} m apart", "Name {$percent}% similar"], $distance, $similarity);
        }

        if ($distance <= $this->rules['adjacent_distance_meters'] && $similarity >= $this->rules['similar_name']) {
            return new MatchResult(true, 75, ["Similar name ({$percent}%) only {$meters} m apart"], $distance, $similarity);
        }

        return MatchResult::noMatch(array_values(array_filter([
            "Name {$percent}% similar, {$meters} m apart",
            $shared !== [] ? 'Shares '.implode(' and ', $shared).', which alone is not enough' : null,
        ])), $distance, $similarity);
    }

    private function sharedIdentifier(MatchCandidate $known, MatchCandidate $candidate): ?string
    {
        foreach ($candidate->identifiers as $source => $ids) {
            if (array_intersect($ids, $known->identifiers[$source] ?? []) !== []) {
                return $source;
            }
        }

        return null;
    }

    private function nameSimilarity(MatchCandidate $known, MatchCandidate $candidate): float
    {
        $best = 0.0;

        foreach ($known->names as $knownName) {
            foreach ($candidate->names as $candidateName) {
                $best = max($best, NameSimilarity::score($knownName, $candidateName));
            }
        }

        return $best;
    }

    private function samePhone(?string $a, ?string $b): bool
    {
        $a = substr((string) preg_replace('/\D+/', '', Text::asciiDigits((string) $a)), -9);
        $b = substr((string) preg_replace('/\D+/', '', Text::asciiDigits((string) $b)), -9);

        return strlen($a) >= 7 && $a === $b;
    }

    private function sameWebsite(?string $a, ?string $b): bool
    {
        $domainA = Domain::registrable($a);

        if ($domainA === null || $domainA !== Domain::registrable($b)) {
            return false;
        }

        // A shared Instagram page or marketplace listing says nothing about identity.
        return Domain::matchesAny($domainA, [...$this->sharedDomains, ...self::SOCIAL_DOMAINS]) === null;
    }

    private function sameBrand(MatchCandidate $known, MatchCandidate $candidate): bool
    {
        if ($known->brandWikidata !== null && $candidate->brandWikidata !== null) {
            return strcasecmp($known->brandWikidata, $candidate->brandWikidata) === 0;
        }

        return $known->brand !== null && $candidate->brand !== null
            && NameSimilarity::score($known->brand, $candidate->brand) >= 0.95;
    }
}

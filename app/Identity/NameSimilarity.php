<?php

namespace App\Identity;

use App\Support\Normalization\Text;

/**
 * How similar two business names are, from 0 to 1, tolerant of the usual
 * differences between sources: branch suffixes ("KFC - King Fahd Road"),
 * generic words ("Restaurant", "مطعم"), spacing ("Al Baik" / "AlBaik")
 * and Arabic letter variants.
 */
final class NameSimilarity
{
    /** Words that say what a business is, not who it is. */
    private const GENERIC_WORDS = [
        'restaurant', 'restaurants', 'resturant', 'cafe', 'café', 'coffee', 'shop', 'store', 'clinic', 'clinics',
        'medical', 'center', 'centre', 'company', 'co', 'est', 'establishment', 'llc', 'the', 'and', 'branch',
        'مطعم', 'مطاعم', 'مقهى', 'كافيه', 'كوفي', 'عياده', 'عيادات', 'مجمع', 'مركز', 'شركه', 'مؤسسه', 'محل', 'متجر', 'فرع', 'و',
    ];

    /** @var list<string>|null GENERIC_WORDS as match keys */
    private static ?array $genericKeys = null;

    /** Separators after which a name usually continues with the branch or location. */
    private const BRANCH_SEPARATORS = '/\s+[-–—|]\s+|\s*[|(\[]|\s+(?:branch|فرع)\b/iu';

    public static function score(string $a, string $b): float
    {
        $keyA = Text::matchKey($a);
        $keyB = Text::matchKey($b);

        if ($keyA === '' || $keyB === '') {
            return 0.0;
        }

        if ($keyA === $keyB) {
            return 1.0;
        }

        $coreA = self::core($a);
        $coreB = self::core($b);

        if ($coreA !== '' && $coreA === $coreB) {
            return 0.95;
        }

        if (str_replace(' ', '', $keyA) === str_replace(' ', '', $keyB)
            || ($coreA !== '' && str_replace(' ', '', $coreA) === str_replace(' ', '', $coreB))) {
            return 0.95;
        }

        $tokensA = self::tokens($coreA !== '' ? $coreA : $keyA);
        $tokensB = self::tokens($coreB !== '' ? $coreB : $keyB);

        return max(self::containment($tokensA, $tokensB), self::jaccard($tokensA, $tokensB), self::characters($coreA ?: $keyA, $coreB ?: $keyB));
    }

    /**
     * The distinctive part of a name: before any branch suffix, without generic words.
     */
    public static function core(string $name): string
    {
        $parts = preg_split(self::BRANCH_SEPARATORS, $name) ?: [$name];
        $main = Text::matchKey((string) $parts[0]);

        if (mb_strlen(str_replace(' ', '', $main)) < 2) {
            $main = Text::matchKey($name);
        }

        $tokens = array_filter(
            explode(' ', $main),
            fn (string $token): bool => $token !== '' && ! in_array($token, self::genericKeys(), true) && ! self::isArticle($token),
        );

        return implode(' ', $tokens);
    }

    /**
     * @return list<string>
     */
    private static function genericKeys(): array
    {
        return self::$genericKeys ??= array_values(array_unique(array_map(Text::matchKey(...), self::GENERIC_WORDS)));
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $value): array
    {
        return array_values(array_unique(array_filter(explode(' ', $value), fn (string $token): bool => $token !== '')));
    }

    /**
     * One name fully contained in the other ("Burger Spot" in "Burger Spot Olaya").
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function containment(array $a, array $b): float
    {
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        if ($short === [] || array_diff($short, $long) !== []) {
            return 0.0;
        }

        $distinctive = array_filter($short, fn (string $token): bool => mb_strlen($token) >= 3);

        return $distinctive === [] ? 0.0 : 0.8;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }

    private static function characters(string $a, string $b): float
    {
        if ($a === '' || $b === '' || max(strlen($a), strlen($b)) > 120) {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        // Character overlap alone is weak evidence; never let it reach the "same name" level.
        return min(0.85, $percent / 100);
    }

    /**
     * "al" and the Arabic article written as a separate word.
     */
    private static function isArticle(string $token): bool
    {
        return in_array($token, ['al', 'el', 'ال'], true);
    }
}

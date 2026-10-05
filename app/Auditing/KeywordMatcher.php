<?php

namespace App\Auditing;

use App\Support\Normalization\Text;

/**
 * Whole-word keyword search in English and Arabic.
 *
 * Text and keywords are normalized the same way (case, diacritics, Arabic
 * letter variants, punctuation). Arabic keywords also match with attached
 * prefixes and pronoun suffixes, e.g. "حجز" matches "للحجز" and "حجزك".
 */
final class KeywordMatcher
{
    private const ARABIC_PREFIX = '(?:و|ف|ب|ل|ك)?(?:ال|لل)?';

    private const ARABIC_SUFFIX = '(?:ك|كم|نا|ها|ه|ي|ات)?';

    /**
     * The first keyword found in a short plain text (a link or button label).
     *
     * @param  list<string>  $keywords
     */
    public static function first(string $text, array $keywords): ?string
    {
        return self::firstInSearchable(Text::searchable($text), $keywords);
    }

    /**
     * The first keyword found in text already normalized with Text::searchable(),
     * such as HtmlDocument::searchableText().
     *
     * @param  list<string>  $keywords
     */
    public static function firstInSearchable(string $haystack, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            if (self::matches($haystack, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }

    private static function matches(string $haystack, string $keyword): bool
    {
        $needle = Text::searchable($keyword);

        if ($needle === '' || $haystack === '') {
            return false;
        }

        $pattern = preg_quote($needle, '/');

        if (preg_match('/\p{Arabic}/u', $needle)) {
            $pattern = self::ARABIC_PREFIX.$pattern.self::ARABIC_SUFFIX;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}

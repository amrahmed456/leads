<?php

namespace App\Support\Normalization;

use Normalizer;

final class Text
{
    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private const ARABIC_LETTER_VARIANTS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
    ];

    /**
     * Convert Arabic-Indic and Persian digits to ASCII digits.
     */
    public static function asciiDigits(string $value): string
    {
        return strtr($value, self::ARABIC_DIGITS);
    }

    /**
     * Trim, drop control/invisible characters and collapse whitespace.
     * Returns null when nothing meaningful is left.
     */
    public static function clean(?string $value, int $maxLength = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * Build a comparison key for a business name: case-, accent-, diacritic-
     * and punctuation-insensitive, with common Arabic letter variants unified.
     */
    public static function matchKey(string $value): string
    {
        return mb_substr(self::searchable($value), 0, 255);
    }

    /**
     * Normalize any amount of text the same way as matchKey(), without the
     * length limit, for keyword search in page content.
     */
    public static function searchable(string $value): string
    {
        $value = mb_strtolower(self::asciiDigits(mb_scrub($value, 'UTF-8')));

        if (class_exists(Normalizer::class)) {
            $value = (string) Normalizer::normalize($value, Normalizer::FORM_KD);
        }

        // Combining marks (Latin accents, Arabic tashkeel) and the Arabic tatweel.
        $value = (string) preg_replace('/[\p{Mn}\x{0640}]+/u', '', $value);
        $value = strtr($value, self::ARABIC_LETTER_VARIANTS);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim($value);
    }
}

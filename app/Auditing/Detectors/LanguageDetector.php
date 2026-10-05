<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\LanguageProfile;
use App\Auditing\HtmlDocument;
use App\Support\Normalization\Text;

/**
 * Determines which languages a homepage is written in, and whether other
 * language versions are linked from it.
 *
 * Counts Arabic and Latin letters in the visible text; the declared <html
 * lang> is only a tie-breaker because it is often left at a default.
 */
final class LanguageDetector
{
    /** Language switcher labels => language code. */
    private const SWITCHER_LABELS = [
        'english' => 'en', 'en' => 'en', 'eng' => 'en',
        'arabic' => 'ar', 'ar' => 'ar', 'العربية' => 'ar', 'عربي' => 'ar', 'عربى' => 'ar',
    ];

    public function detect(HtmlDocument $document): LanguageProfile
    {
        $declared = $this->code($document->lang());
        $text = $document->title().' '.$document->visibleText();

        $arabic = (int) preg_match_all('/\p{Arabic}/u', $text);
        $latin = (int) preg_match_all('/[A-Za-z]/', $text);
        $total = max(1, $arabic + $latin);

        $hasArabic = ($arabic >= 40 && $arabic / $total >= 0.1) || ($declared === 'ar' && $arabic >= 10);
        $hasEnglish = ($latin >= 150 && $latin / $total >= 0.15) || ($declared === 'en' && $latin >= 50 && $latin / $total >= 0.3);

        $primary = match (true) {
            $arabic === 0 && $latin === 0 => $declared,
            $arabic >= $latin => 'ar',
            $declared !== null && $declared !== 'ar' => $declared,
            default => 'en',
        };

        $alternates = array_values(array_unique(array_filter(
            [...array_map(fn (string $lang): ?string => $this->code($lang), $document->hreflangs()), ...$this->switcherLanguages($document)],
            fn (?string $code): bool => $code !== null && $code !== $primary,
        )));

        return new LanguageProfile($declared, $primary, $hasArabic, $hasEnglish, $alternates);
    }

    private function code(?string $lang): ?string
    {
        $code = strtolower(substr(trim((string) $lang), 0, 2));

        return preg_match('/^[a-z]{2}$/', $code) ? $code : null;
    }

    /**
     * Languages offered by links such as "English", "العربية", /en/ or ?lang=ar.
     *
     * @return list<string>
     */
    private function switcherLanguages(HtmlDocument $document): array
    {
        $labels = [];

        foreach (self::SWITCHER_LABELS as $label => $code) {
            $labels[Text::searchable($label)] = $code;
        }

        $codes = [];

        foreach ($document->links() as $link) {
            $label = Text::searchable($link['text']);

            if (isset($labels[$label])) {
                $codes[] = $labels[$label];

                continue;
            }

            $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($link['href'], PHP_URL_HOST)));

            if ($host !== $document->host()) {
                continue;
            }

            if (preg_match('#^/(ar|en)(?:/|$)#i', (string) parse_url($link['href'], PHP_URL_PATH), $matches)
                || preg_match('/(?:^|&)(?:lang|language|locale)=(ar|en)\b/i', (string) parse_url($link['href'], PHP_URL_QUERY), $matches)) {
                $codes[] = strtolower($matches[1]);
            }
        }

        return $codes;
    }
}

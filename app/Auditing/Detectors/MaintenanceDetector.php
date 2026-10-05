<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\HtmlDocument;
use App\Auditing\KeywordMatcher;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Signs that a website is outdated, abandoned or only a placeholder.
 */
final class MaintenanceDetector implements SignalDetector
{
    /** Copyright years at least this many years old count as outdated. */
    private const STALE_COPYRIGHT_YEARS = 3;

    /** Placeholder pages are short; longer pages may just announce something "coming soon". */
    private const PLACEHOLDER_MAX_TEXT = 600;

    private const PLACEHOLDERS = [
        'under construction', 'coming soon', 'website coming soon', 'site under maintenance', 'this domain is for sale',
        'domain is parked', 'buy this domain', 'الموقع تحت الإنشاء', 'الموقع قيد الإنشاء', 'تحت الصيانة', 'قريبا',
    ];

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        $signal = new SignalBuilder(WebsiteSignal::OutdatedContent);
        $text = $document->visibleText();

        $year = $this->latestCopyrightYear($text);

        if ($year !== null) {
            $signal->set('copyright_year', $year);

            if ($year <= $context->currentYear - self::STALE_COPYRIGHT_YEARS) {
                $signal->add(Confidence::Medium, "Copyright notice last updated in {$year}");
            }
        }

        if ($document->exists('//embed[contains(@src, ".swf")] | //object[contains(@data, ".swf")] | //object[@type="application/x-shockwave-flash"]')) {
            $signal->add(Confidence::High, 'Uses Flash, which browsers no longer support');
        }

        if ($document->exists('//frameset')) {
            $signal->add(Confidence::Medium, 'Built with HTML frames');
        }

        $placeholder = KeywordMatcher::firstInSearchable($document->searchableText(), self::PLACEHOLDERS);

        if ($placeholder !== null && mb_strlen($text) <= self::PLACEHOLDER_MAX_TEXT) {
            $signal->add(Confidence::High, "Placeholder page (“{$placeholder}”)")->set('placeholder', true);
        }

        if (preg_match('/^\s*<!DOCTYPE\s+html\s+PUBLIC\s+"-\/\/W3C\/\/DTD\s+(?:HTML\s+4|XHTML\s+1\.0\s+Transitional)/i', $document->html)) {
            $signal->hint('Legacy HTML 4 / XHTML doctype');
        }

        return [$signal->build()];
    }

    private function latestCopyrightYear(string $text): ?int
    {
        // "© 2019", "Copyright 2015-2019", "حقوق ... 2020", and the reversed "2024©".
        preg_match_all('/(?:©|\(c\)|copyright|حقوق)\D{0,40}?((?:19|20)\d{2})(?:\s*[-–—]\s*((?:19|20)\d{2}))?/iu', $text, $matches);
        preg_match_all('/\b((?:19|20)\d{2})\s*©/u', $text, $reversed);

        $years = array_map('intval', array_filter([...$matches[1], ...$matches[2], ...$reversed[1]]));

        return $years === [] ? null : max($years);
    }
}

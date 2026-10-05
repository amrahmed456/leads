<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\Data\DetectedSignal;
use App\Auditing\HtmlDocument;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Mobile readiness signals.
 *
 * These are signals, not a usability verdict: a correct viewport and
 * responsive CSS make a good mobile layout likely, but only a real device
 * test can confirm it. External stylesheets are not downloaded, so media
 * queries in them are not seen.
 */
final class MobileDetector implements SignalDetector
{
    /** @var array<string, string> name => pattern in the page source */
    private const FRAMEWORKS = [
        'Bootstrap' => '#bootstrap(?:\.min)?\.css|class="[^"]*\bcol-(?:xs|sm|md|lg|xl)-\d#i',
        'Tailwind CSS' => '#class="[^"]*\b(?:sm|md|lg|xl):[a-z]#',
        'Foundation' => '#foundation(?:\.min)?\.css#i',
        'Bulma' => '#bulma(?:\.min)?\.css#i',
    ];

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        return [$this->viewport($document), $this->responsiveDesign($document, $context)];
    }

    private function viewport(HtmlDocument $document): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::MobileViewport);
        $content = $document->meta('viewport');

        if ($content === null) {
            return $signal->build();
        }

        $signal->set('content', mb_substr($content, 0, 200));

        if (preg_match('/width\s*=\s*device-width/i', $content)) {
            $signal->add(Confidence::High, "Viewport: {$content}");
        } else {
            $signal->add(Confidence::Low, "Viewport with a fixed width: {$content}");
        }

        if (preg_match('/user-scalable\s*=\s*(?:no|0)/i', $content)) {
            $signal->set('zoom_disabled', true);
        }

        return $signal->build();
    }

    private function responsiveDesign(HtmlDocument $document, AnalysisContext $context): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::ResponsiveDesign);

        foreach (self::FRAMEWORKS as $name => $pattern) {
            if (preg_match($pattern, $document->html)) {
                $signal->add(Confidence::Medium, "Responsive CSS framework: {$name}");
            }
        }

        $mediaQueries = (int) preg_match_all('/@media[^{]*(?:max|min)-width/i', $document->inlineStyles());

        if ($mediaQueries > 0) {
            $signal->add(Confidence::Medium, "{$mediaQueries} CSS media queries on the page");
        }

        foreach ($context->technologiesIn('site_builder', 'page_builder') as $technology) {
            $signal->add(Confidence::Medium, "Built with {$technology->name}, which produces responsive layouts");
        }

        if ($document->exists('//img[@srcset] | //picture')) {
            $signal->hint('Responsive images (srcset)');
        }

        return $signal->build();
    }
}

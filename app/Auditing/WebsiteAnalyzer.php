<?php

namespace App\Auditing;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\Data\DetectedSignal;
use App\Auditing\Data\DetectedTechnology;
use App\Auditing\Data\WebsiteAnalysis;
use App\Auditing\Detectors\BookingDetector;
use App\Auditing\Detectors\ContactDetector;
use App\Auditing\Detectors\EcommerceDetector;
use App\Auditing\Detectors\LanguageDetector;
use App\Auditing\Detectors\MaintenanceDetector;
use App\Auditing\Detectors\MobileAppDetector;
use App\Auditing\Detectors\MobileDetector;
use App\Auditing\Detectors\OrderingDetector;
use App\Auditing\Detectors\SignalDetector;
use App\Auditing\Detectors\TechnologyDetector;
use App\Enums\Confidence;
use App\Enums\Country;
use App\Enums\WebsiteSignal;
use App\Support\Normalization\Text;
use Illuminate\Support\Carbon;

/**
 * Runs every deterministic detector over a homepage. No AI, no network.
 */
final class WebsiteAnalyzer
{
    public function __construct(
        private readonly TechnologyDetector $technologies,
        private readonly LanguageDetector $languages,
        private readonly ContactDetector $contacts,
        private readonly BookingDetector $booking,
        private readonly OrderingDetector $ordering,
        private readonly EcommerceDetector $ecommerce,
        private readonly MobileDetector $mobile,
        private readonly MobileAppDetector $mobileApp,
        private readonly MaintenanceDetector $maintenance,
    ) {}

    /**
     * @param  Country|null  $country  Used to recognize local phone number formats
     */
    public function analyze(HtmlDocument $document, ?Country $country = null, ?int $currentYear = null): WebsiteAnalysis
    {
        $technologies = $this->technologies->detect($document);
        $context = new AnalysisContext($country, $technologies, $currentYear ?? Carbon::now()->year);

        $signals = [];

        foreach ($this->signalDetectors() as $detector) {
            foreach ($detector->detect($document, $context) as $signal) {
                $signals[$signal->signal->value] = $signal;
            }
        }

        $signals[WebsiteSignal::Analytics->value] = $this->analytics($context);

        return new WebsiteAnalysis(
            title: $document->title(),
            metaDescription: Text::clean($document->meta('description'), 500),
            languages: $this->languages->detect($document),
            technologies: $technologies,
            cms: $this->technologies->platform($technologies),
            signals: $signals,
        );
    }

    /**
     * @return list<SignalDetector>
     */
    private function signalDetectors(): array
    {
        return [$this->contacts, $this->booking, $this->ordering, $this->ecommerce, $this->mobile, $this->mobileApp, $this->maintenance];
    }

    private function analytics(AnalysisContext $context): DetectedSignal
    {
        $tools = array_map(
            fn (DetectedTechnology $technology): string => $technology->name,
            $context->technologiesIn('analytics', 'advertising'),
        );

        return new DetectedSignal(
            WebsiteSignal::Analytics,
            $tools === [] ? Confidence::None : Confidence::High,
            $tools === [] ? [] : ['Tracking: '.implode(', ', $tools)],
            ['tools' => $tools],
        );
    }
}

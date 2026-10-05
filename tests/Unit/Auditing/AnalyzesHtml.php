<?php

namespace Tests\Unit\Auditing;

use App\Auditing\Data\WebsiteAnalysis;
use App\Auditing\Detectors\BookingDetector;
use App\Auditing\Detectors\ContactDetector;
use App\Auditing\Detectors\EcommerceDetector;
use App\Auditing\Detectors\LanguageDetector;
use App\Auditing\Detectors\MaintenanceDetector;
use App\Auditing\Detectors\MobileAppDetector;
use App\Auditing\Detectors\MobileDetector;
use App\Auditing\Detectors\OrderingDetector;
use App\Auditing\Detectors\TechnologyDetector;
use App\Auditing\HtmlDocument;
use App\Auditing\WebsiteAnalyzer;
use App\Enums\Country;
use App\Support\Normalization\EmailNormalizer;
use App\Support\Normalization\PhoneNormalizer;
use App\Support\Normalization\SocialLinkNormalizer;
use App\Support\Normalization\UrlNormalizer;

/**
 * Runs the real analyzer, without the framework, against HTML.
 */
trait AnalyzesHtml
{
    /** The audits are evaluated as if it were this year. */
    private const CURRENT_YEAR = 2026;

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $cookies
     */
    private function analyze(string $html, string $url = 'https://example.sa/', array $headers = [], array $cookies = [], Country $country = Country::SaudiArabia): WebsiteAnalysis
    {
        return $this->analyzer()->analyze(new HtmlDocument($html, $url, $headers, $cookies), $country, self::CURRENT_YEAR);
    }

    private function analyzeFixture(string $name, string $url, Country $country = Country::SaudiArabia): WebsiteAnalysis
    {
        return $this->analyze($this->websiteFixture($name), $url, country: $country);
    }

    private function websiteFixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/websites/{$name}");
    }

    private function analyzer(): WebsiteAnalyzer
    {
        $urls = new UrlNormalizer;
        $phones = new PhoneNormalizer;

        return new WebsiteAnalyzer(
            new TechnologyDetector,
            new LanguageDetector,
            new ContactDetector($phones, new EmailNormalizer, new SocialLinkNormalizer($urls, $phones)),
            new BookingDetector,
            new OrderingDetector,
            new EcommerceDetector,
            new MobileDetector,
            new MobileAppDetector,
            new MaintenanceDetector,
        );
    }
}

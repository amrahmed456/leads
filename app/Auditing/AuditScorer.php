<?php

namespace App\Auditing;

use App\Auditing\Data\AuditScore;
use App\Auditing\Data\FetchResult;
use App\Auditing\Data\LanguageProfile;
use App\Auditing\Data\WebsiteAnalysis;
use App\Enums\OpportunityPriority;
use App\Enums\WebsiteSignal;

/**
 * Scores a website's technical and digital presence from measurable signals.
 *
 * This is not a visual or UX judgment. Each check is worth a configurable
 * number of points (config/auditing.php); the score is the share of
 * applicable points earned, and every check is kept so the score can be
 * explained. Booking, ordering and e-commerce checks apply only to
 * categories that need them.
 */
final class AuditScorer
{
    /** Template and framework default titles that were never replaced. */
    private const GENERIC_TITLES = [
        'home', 'home page', 'home page title', 'homepage', 'index', 'untitled', 'document', 'welcome',
        'my wordpress blog', 'just another wordpress site', 'my site', 'react app', 'create next app', 'vite app',
    ];

    /**
     * @param  array<string, int>  $weights  Check key => points
     */
    public function __construct(private readonly array $weights) {}

    /**
     * @param  array<string, OpportunityPriority>  $needs  Capabilities the business category needs, keyed by OpportunityType value
     */
    public function score(WebsiteAnalysis $analysis, FetchResult $fetch, array $needs = []): AuditScore
    {
        $checks = [];

        $check = function (string $key, bool $passed, string $passedLabel, string $failedLabel) use (&$checks): void {
            $max = (int) ($this->weights[$key] ?? 0);

            if ($max > 0) {
                $checks[] = [
                    'key' => $key,
                    'label' => $passed ? $passedLabel : $failedLabel,
                    'points' => $passed ? $max : 0,
                    'max' => $max,
                    'passed' => $passed,
                ];
            }
        };

        $check('https', $fetch->isHttps() && $fetch->sslValid, 'Served over HTTPS', $fetch->isHttps() ? 'Invalid SSL certificate' : 'Not served over HTTPS');
        $check('mobile_viewport', $analysis->has(WebsiteSignal::MobileViewport), 'Mobile viewport configured', 'No mobile viewport configured');
        $genericTitle = $analysis->title !== null && in_array(mb_strtolower(trim($analysis->title)), self::GENERIC_TITLES, true);
        $check('title', $analysis->title !== null && ! $genericTitle, 'Page title present', $genericTitle ? "Generic page title (“{$analysis->title}”)" : 'Missing page title');
        $check('meta_description', $analysis->metaDescription !== null, 'Meta description present', 'Missing meta description');
        $check('phone', $analysis->has(WebsiteSignal::Phone), 'Phone number on the website', 'No phone number on the website');
        $check('email', $analysis->has(WebsiteSignal::Email), 'E-mail address on the website', 'No e-mail address on the website');
        $check('contact_form', $analysis->has(WebsiteSignal::ContactForm), 'Contact form', 'No contact form');
        $check('whatsapp', $analysis->has(WebsiteSignal::Whatsapp), 'WhatsApp contact', 'No WhatsApp contact');

        if (isset($needs['online_booking'])) {
            $check('online_booking', $analysis->has(WebsiteSignal::Booking), 'Online booking', 'No online booking');
        }

        if (isset($needs['online_ordering'])) {
            $check('online_ordering', $analysis->has(WebsiteSignal::OnlineOrdering) || $analysis->has(WebsiteSignal::Cart), 'Online ordering', 'No online ordering');
        }

        if (isset($needs['ecommerce'])) {
            $check('ecommerce', $analysis->has(WebsiteSignal::Cart), 'Online store with a cart', 'No online store with a cart');
        }

        $check('languages', $analysis->languages->isMultilingual(), 'Arabic and English content', $this->singleLanguage($analysis->languages));

        $outdated = $analysis->signal(WebsiteSignal::OutdatedContent);
        $check('maintained', ! $outdated->isDetected(), 'No signs of an outdated site', 'Outdated: '.lcfirst($outdated->evidence[0] ?? 'signs of an unmaintained site'));

        $possible = array_sum(array_column($checks, 'max'));
        $earned = array_sum(array_column($checks, 'points'));

        return new AuditScore($possible > 0 ? (int) round($earned / $possible * 100) : 0, $checks);
    }

    private function singleLanguage(LanguageProfile $languages): string
    {
        return match (true) {
            $languages->hasArabic => 'Only in Arabic',
            $languages->hasEnglish => 'Only in English',
            default => 'No Arabic or English content detected',
        };
    }
}

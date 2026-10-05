<?php

namespace Tests\Unit\Auditing;

use App\Auditing\AuditScorer;
use App\Auditing\Data\FetchResult;
use App\Enums\OpportunityPriority;
use PHPUnit\Framework\TestCase;

class AuditScorerTest extends TestCase
{
    use AnalyzesHtml;

    private const WEIGHTS = [
        'https' => 15, 'mobile_viewport' => 15, 'title' => 5, 'meta_description' => 5, 'phone' => 8, 'email' => 4,
        'contact_form' => 8, 'whatsapp' => 10, 'online_booking' => 10, 'online_ordering' => 10, 'ecommerce' => 10,
        'languages' => 10, 'maintained' => 5,
    ];

    public function test_scores_a_restaurant_website_with_reasons_for_every_point(): void
    {
        $analysis = $this->analyzeFixture('arabic-restaurant.html', 'https://alreef.sa/');

        $score = (new AuditScorer(self::WEIGHTS))->score($analysis, $this->fetch('https://alreef.sa/'), [
            'online_ordering' => OpportunityPriority::High,
            'online_booking' => OpportunityPriority::Medium,
        ]);

        // 58 of 105 applicable points (e-commerce does not apply to restaurants).
        $this->assertSame(55, $score->score);
        $this->assertSame([
            'Served over HTTPS', 'Mobile viewport configured', 'Page title present', 'Meta description present',
            'Phone number on the website', 'WhatsApp contact',
        ], $score->positives());
        $this->assertSame([
            'No e-mail address on the website', 'No contact form', 'No online booking', 'No online ordering',
            'Only in Arabic', 'Outdated: copyright notice last updated in 2019',
        ], $score->negatives());
        $this->assertNotContains('ecommerce', array_column($score->checks, 'key'));
    }

    public function test_capabilities_a_category_does_not_need_are_not_held_against_it(): void
    {
        $analysis = $this->analyzeFixture('dental-clinic.html', 'https://smiledental.ae/');
        $scorer = new AuditScorer(self::WEIGHTS);

        $asClinic = $scorer->score($analysis, $this->fetch('https://smiledental.ae/'), ['online_booking' => OpportunityPriority::High]);
        $asLawFirm = $scorer->score($analysis, $this->fetch('https://smiledental.ae/'), []);

        $this->assertSame(['https', 'mobile_viewport', 'title', 'meta_description', 'phone', 'email', 'contact_form', 'whatsapp', 'online_booking', 'languages', 'maintained'], array_column($asClinic->checks, 'key'));
        $this->assertNotContains('online_booking', array_column($asLawFirm->checks, 'key'));
        $this->assertSame(['No WhatsApp contact'], $asClinic->negatives());
    }

    public function test_an_invalid_certificate_or_plain_http_loses_the_https_points(): void
    {
        $analysis = $this->analyze('<title>Site</title>');
        $scorer = new AuditScorer(self::WEIGHTS);

        $this->assertContains('Invalid SSL certificate', $scorer->score($analysis, $this->fetch('https://example.sa/', sslValid: false))->negatives());
        $this->assertContains('Not served over HTTPS', $scorer->score($analysis, $this->fetch('http://example.sa/'))->negatives());
    }

    public function test_a_template_default_title_does_not_count_as_a_title(): void
    {
        $analysis = $this->analyze('<title>Home Page Title</title>');

        $score = (new AuditScorer(self::WEIGHTS))->score($analysis, $this->fetch('https://saldwich.com/ar'));

        $this->assertContains('Generic page title (“Home Page Title”)', $score->negatives());
    }

    private function fetch(string $url, bool $sslValid = true): FetchResult
    {
        return new FetchResult($url, $url, 200, ['content-type' => 'text/html'], [], '', false, 300, [], $sslValid);
    }
}

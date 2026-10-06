<?php

namespace Tests\Unit\Auditing;

use App\Auditing\CorporatePageDetector;
use App\Auditing\HtmlDocument;
use App\Auditing\StructuredDataExtractor;
use PHPUnit\Framework\TestCase;

class StructuredDataExtractorTest extends TestCase
{
    public function test_json_ld_graph_entities_are_normalized_with_parent_organization_and_branch(): void
    {
        $entities = $this->extract()['entities'];

        $this->assertCount(2, $entities, 'The dentist and the microdata restaurant; WebSite and BreadcrumbList are not organizations.');

        $dentist = $entities[0];
        $this->assertSame(['Dentist', 'MedicalClinic'], $dentist['types']);
        $this->assertSame('json-ld', $dentist['format']);
        $this->assertSame('Smile Dental Olaya', $dentist['name']);
        $this->assertSame('+966 11 200 3000', $dentist['telephone']);
        $this->assertSame('olaya@smiledental.sa', $dentist['email']);
        $this->assertSame('Olaya Street, Riyadh, SA', $dentist['address']);
        $this->assertSame(24.6905, $dentist['latitude']);
        $this->assertSame('Sa-Th 09:00-21:00', $dentist['opening_hours']);
        $this->assertSame(['https://www.instagram.com/smiledental', 'https://x.com/smiledental'], $dentist['same_as']);
        $this->assertSame('Smile Healthcare Holding', $dentist['parent_organization']);
        $this->assertSame('Smile Dental Group', $dentist['branch_of']);
        $this->assertSame('Smile Dental', $dentist['brand']);
        $this->assertTrue($dentist['accepts_reservations']);
    }

    public function test_microdata_items_are_read_without_mixing_in_nested_items(): void
    {
        $restaurant = $this->extract()['entities'][1];

        $this->assertSame(['Restaurant'], $restaurant['types']);
        $this->assertSame('microdata', $restaurant['format']);
        $this->assertSame('Smile Cafe', $restaurant['name']);
        $this->assertSame('+966 11 200 4000', $restaurant['telephone']);
        $this->assertSame('Tahlia Street', $restaurant['address']);
    }

    public function test_rdfa_items_are_read(): void
    {
        $html = '<div vocab="https://schema.org/" typeof="LocalBusiness"><span property="name">Olaya Tailors</span><span property="telephone">0112223333</span></div>';

        $entities = (new StructuredDataExtractor)->extract(new HtmlDocument($html, 'https://olaya.sa/'))['entities'];

        $this->assertSame([['types' => ['LocalBusiness'], 'format' => 'microdata', 'name' => 'Olaya Tailors', 'telephone' => '0112223333']], $entities);
    }

    public function test_a_page_without_markup_has_no_entities(): void
    {
        $this->assertSame(['entities' => []], (new StructuredDataExtractor)->extract(new HtmlDocument('<p>Hello</p>', 'https://a.sa/')));
    }

    public function test_corporate_pages_on_the_same_site_are_detected(): void
    {
        $pages = (new CorporatePageDetector)->detect($this->document());

        $this->assertSame([
            'locations' => ['https://smiledental.sa/branches'],
            'franchise' => ['https://smiledental.sa/franchise'],
            'careers' => ['https://smiledental.sa/careers'],
        ], $pages, 'Links to other websites do not count.');
    }

    /**
     * @return array{entities: list<array<string, mixed>>}
     */
    private function extract(): array
    {
        return (new StructuredDataExtractor)->extract($this->document());
    }

    private function document(): HtmlDocument
    {
        return new HtmlDocument((string) file_get_contents(__DIR__.'/../../Fixtures/websites/chain-branch.html'), 'https://smiledental.sa/');
    }
}

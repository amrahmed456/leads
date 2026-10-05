<?php

namespace Tests\Unit\Discovery;

use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Providers\OpenStreetMap\OsmTagMap;
use App\Discovery\Providers\OpenStreetMap\OverpassQueryBuilder;
use App\Enums\BusinessCategory;
use App\Enums\Country;
use PHPUnit\Framework\TestCase;

class OverpassQueryBuilderTest extends TestCase
{
    public function test_it_selects_the_category_tags_within_the_radius(): void
    {
        $ql = $this->build(new DiscoveryQuery(Country::SaudiArabia, 'Riyadh', 24.7136, 46.6753, 5000, BusinessCategory::Dentist));

        $this->assertStringStartsWith('[out:json][timeout:120];', $ql);
        $this->assertStringContainsString('nwr["amenity"~"^(dentist)$"](around:5000,24.7136000,46.6753000);', $ql);
        $this->assertStringContainsString('nwr["healthcare"~"^(dentist)$"](around:5000,24.7136000,46.6753000);', $ql);
        $this->assertStringContainsString('out tags center qt 3000;', $ql);
        $this->assertStringNotContainsString('name', $ql);
    }

    public function test_keywords_add_a_case_insensitive_name_search_over_business_elements(): void
    {
        $ql = $this->build(new DiscoveryQuery(
            Country::SaudiArabia, 'Riyadh', 24.7136, 46.6753, 2000, BusinessCategory::Restaurant, ['مطعم', 'restaurant', "Kudu's"],
        ));

        $this->assertStringContainsString(
            'nwr[~"^(amenity|shop|office|tourism|leisure|healthcare|craft)$"~"."][~"^(name|name:en|name:ar|brand|brand:en|brand:ar)$"~"مطعم|restaurant|Kudu\'s",i](around:2000,24.7136000,46.6753000);',
            $ql,
        );
    }

    public function test_every_category_is_supported_by_openstreetmap(): void
    {
        $map = new OsmTagMap;

        foreach (BusinessCategory::cases() as $category) {
            $this->assertNotEmpty($map->filtersFor($category), "No OSM tags for {$category->value}");
        }
    }

    private function build(DiscoveryQuery $query): string
    {
        return (new OverpassQueryBuilder(new OsmTagMap))->build($query, 120, 3000);
    }
}

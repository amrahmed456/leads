<?php

namespace Tests\Unit\Discovery;

use App\Discovery\BusinessNormalizer;
use App\Discovery\Data\DiscoveredBusiness;
use App\Support\Normalization\EmailNormalizer;
use App\Support\Normalization\PhoneNormalizer;
use App\Support\Normalization\SocialLinkNormalizer;
use App\Support\Normalization\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class BusinessNormalizerTest extends TestCase
{
    private BusinessNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $urls = new UrlNormalizer;
        $phones = new PhoneNormalizer;

        $this->normalizer = new BusinessNormalizer($phones, $urls, new EmailNormalizer, new SocialLinkNormalizer($urls, $phones));
    }

    public function test_a_record_with_only_the_required_fields_is_kept(): void
    {
        $business = $this->normalizer->normalize(new DiscoveredBusiness('openstreetmap', 'node/1', 'Minimal Shop'));

        $this->assertNotNull($business);
        $this->assertSame('Minimal Shop', $business->name);
        $this->assertNull($business->phone);
        $this->assertNull($business->website);
        $this->assertNull($business->email);
        $this->assertNull($business->rating);
        $this->assertNull($business->reviewsCount);
        $this->assertNull($business->latitude);
        $this->assertSame([], $business->socialLinks);
    }

    public function test_records_without_a_usable_name_or_id_are_rejected(): void
    {
        $this->assertNull($this->normalizer->normalize(new DiscoveredBusiness('openstreetmap', 'node/1', "  \u{200B} ")));
        $this->assertNull($this->normalizer->normalize(new DiscoveredBusiness('openstreetmap', 'node/1', '!!!')));
        $this->assertNull($this->normalizer->normalize(new DiscoveredBusiness('openstreetmap', ' ', 'Valid Name')));
    }

    public function test_contact_details_are_normalized(): void
    {
        $business = $this->normalizer->normalize(new DiscoveredBusiness(
            provider: 'openstreetmap',
            providerId: 'node/1',
            name: '  Al   Reef ',
            countryCode: 'sa',
            phone: '050 123 4567',
            website: 'ALREEF.SA/?utm_campaign=x',
            email: 'mailto:Info@AlReef.SA',
            socialLinks: ['instagram' => '@alreef', 'facebook' => 'not a profile url/', 'unknown' => 'x'],
        ));

        $this->assertSame('Al Reef', $business->name);
        $this->assertSame('SA', $business->countryCode);
        $this->assertSame('+966501234567', $business->phone);
        $this->assertSame('https://alreef.sa', $business->website);
        $this->assertSame('info@alreef.sa', $business->email);
        $this->assertSame(['instagram' => 'https://www.instagram.com/alreef'], $business->socialLinks);
    }

    public function test_a_social_profile_given_as_website_is_moved_to_social_links(): void
    {
        $business = $this->normalizer->normalize(new DiscoveredBusiness(
            provider: 'openstreetmap',
            providerId: 'node/1',
            name: 'Burger Spot',
            website: 'https://www.instagram.com/burgerspot.sa/',
        ));

        $this->assertNull($business->website);
        $this->assertSame(['instagram' => 'https://www.instagram.com/burgerspot.sa/'], $business->socialLinks);
    }

    public function test_invalid_values_are_dropped_instead_of_stored(): void
    {
        $business = $this->normalizer->normalize(new DiscoveredBusiness(
            provider: 'openstreetmap',
            providerId: 'node/1',
            name: 'Broken Data Cafe',
            alternateName: 'BROKEN data café',
            countryCode: 'Saudi Arabia',
            latitude: 123.0,
            longitude: 46.7,
            phone: 'unknown',
            website: 'javascript:alert(1)',
            email: 'not-an-email',
            rating: 7.5,
            reviewsCount: -3,
        ));

        $this->assertNull($business->alternateName, 'An alternate name equal to the name is redundant.');
        $this->assertNull($business->countryCode);
        $this->assertNull($business->latitude);
        $this->assertNull($business->longitude);
        $this->assertNull($business->phone);
        $this->assertNull($business->website);
        $this->assertNull($business->email);
        $this->assertNull($business->rating);
        $this->assertNull($business->reviewsCount);
    }
}

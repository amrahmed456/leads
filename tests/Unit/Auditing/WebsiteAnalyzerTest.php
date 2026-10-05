<?php

namespace Tests\Unit\Auditing;

use App\Enums\Confidence;
use App\Enums\Country;
use App\Enums\WebsiteSignal;
use PHPUnit\Framework\TestCase;

class WebsiteAnalyzerTest extends TestCase
{
    use AnalyzesHtml;

    public function test_detects_the_signals_of_an_arabic_restaurant_homepage(): void
    {
        $analysis = $this->analyzeFixture('arabic-restaurant.html', 'https://alreef.sa/');

        $this->assertSame('مطعم الريف | المأكولات الشعبية', $analysis->title);
        $this->assertSame('مطعم الريف - أشهى المأكولات الشعبية في الرياض', $analysis->metaDescription);
        $this->assertSame(['+966112345678'], $analysis->signal(WebsiteSignal::Phone)->data['numbers']);
        $this->assertSame(['+966501234567'], $analysis->signal(WebsiteSignal::Whatsapp)->data['numbers']);
        $this->assertSame(['instagram' => 'https://www.instagram.com/alreef.sa/'], $analysis->signal(WebsiteSignal::SocialProfiles)->data['profiles']);
        $this->assertSame(['Talabat', 'HungerStation'], $analysis->signal(WebsiteSignal::DeliveryApps)->data['platforms']);
        $this->assertFalse($analysis->has(WebsiteSignal::OnlineOrdering), 'Delivery apps are not the restaurant\'s own ordering.');
        $this->assertTrue($analysis->signal(WebsiteSignal::OnlineOrdering)->data['menu']);
        $this->assertFalse($analysis->has(WebsiteSignal::Email));
        $this->assertFalse($analysis->has(WebsiteSignal::ContactForm));
        $this->assertFalse($analysis->has(WebsiteSignal::Booking));
        $this->assertFalse($analysis->has(WebsiteSignal::Ecommerce));
        $this->assertTrue($analysis->has(WebsiteSignal::MobileViewport));
        $this->assertTrue($analysis->has(WebsiteSignal::Analytics));
        $this->assertSame(['Copyright notice last updated in 2019'], $analysis->signal(WebsiteSignal::OutdatedContent)->evidence);
        $this->assertSame('WordPress', $analysis->cms);
        $this->assertSame('ar', $analysis->languages->primary);
        $this->assertTrue($analysis->languages->hasArabic);
        $this->assertFalse($analysis->languages->hasEnglish);
        $this->assertFalse($analysis->languages->isMultilingual());
    }

    public function test_detects_booking_contact_form_and_bilingual_content_on_a_clinic_homepage(): void
    {
        $analysis = $this->analyzeFixture('dental-clinic.html', 'https://smiledental.ae/', Country::UnitedArabEmirates);

        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::Booking)->confidence, 'A booking link plus a date picker corroborate each other.');
        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::ContactForm)->confidence);
        $this->assertSame(['hello@smiledental.ae'], $analysis->signal(WebsiteSignal::Email)->data['addresses']);
        $this->assertSame(['+97142345678'], $analysis->signal(WebsiteSignal::Phone)->data['numbers'], 'The copyright years "2024 - 2026" are not a phone number.');
        $this->assertSame(['Responsive CSS framework: Bootstrap'], $analysis->signal(WebsiteSignal::ResponsiveDesign)->evidence);
        $this->assertSame(['ar'], $analysis->languages->alternates);
        $this->assertTrue($analysis->languages->isMultilingual());
        $this->assertFalse($analysis->has(WebsiteSignal::OutdatedContent));
    }

    public function test_detects_an_online_store_with_a_cart(): void
    {
        $analysis = $this->analyzeFixture('shopify-store.html', 'https://desertrose.sa/');

        $this->assertSame('Shopify', $analysis->cms);
        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::Ecommerce)->confidence);
        $this->assertSame(['Shopify'], $analysis->signal(WebsiteSignal::Ecommerce)->data['platforms']);
        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::Cart)->confidence);
        $this->assertTrue($analysis->languages->hasEnglish);
    }

    public function test_flags_an_outdated_website(): void
    {
        $analysis = $this->analyzeFixture('legacy-site.html', 'http://gulftrading.com.sa/');

        $outdated = $analysis->signal(WebsiteSignal::OutdatedContent);

        $this->assertSame(Confidence::High, $outdated->confidence);
        $this->assertContains('Uses Flash, which browsers no longer support', $outdated->evidence);
        $this->assertContains('Copyright notice last updated in 2012', $outdated->evidence);
        $this->assertSame(2012, $outdated->data['copyright_year']);
        $this->assertFalse($analysis->has(WebsiteSignal::MobileViewport));
        $this->assertNull($analysis->metaDescription);
    }

    public function test_whatsapp_links_are_detected_with_their_number(): void
    {
        $analysis = $this->analyze('<a href="https://api.whatsapp.com/send?phone=971501234567&text=Hi">Chat</a>');

        $signal = $analysis->signal(WebsiteSignal::Whatsapp);
        $this->assertSame(Confidence::High, $signal->confidence);
        $this->assertSame(['+971501234567'], $signal->data['numbers']);
    }

    public function test_a_whatsapp_chat_widget_is_detected(): void
    {
        $analysis = $this->analyze('<div class="joinchat joinchat--right"><div class="joinchat__button"></div></div>');

        $this->assertSame(Confidence::Medium, $analysis->signal(WebsiteSignal::Whatsapp)->confidence);
    }

    public function test_a_whatsapp_mention_without_a_link_is_only_a_hint(): void
    {
        $analysis = $this->analyze('<p>تواصل معنا عبر الواتساب على الرقم المذكور</p>');

        $this->assertSame(Confidence::Low, $analysis->signal(WebsiteSignal::Whatsapp)->confidence);
        $this->assertFalse($analysis->has(WebsiteSignal::Whatsapp));
    }

    public function test_phone_numbers_are_found_in_links_and_text_and_normalized(): void
    {
        $this->assertSame(['+966501234567'], $this->analyze('<a href="tel:+966-50-123-4567">Call</a>')->signal(WebsiteSignal::Phone)->data['numbers']);
        $this->assertSame(['+966920012345'], $this->analyze('<p>الرقم الموحد: ٩٢٠٠١٢٣٤٥</p>')->signal(WebsiteSignal::Phone)->data['numbers']);
        $this->assertFalse($this->analyze('<p>Opened 2010.05.12, renovated 2019 - 2024.</p>', country: Country::Kuwait)->has(WebsiteSignal::Phone));
    }

    public function test_email_addresses_are_found_but_image_names_and_placeholders_are_ignored(): void
    {
        $analysis = $this->analyze('<p>sales@gulftrading.sa</p><img src="logo@2x.png" alt="logo@2x.png"><p>you@example.com</p>');

        $this->assertSame(['sales@gulftrading.sa'], $analysis->signal(WebsiteSignal::Email)->data['addresses']);
        $this->assertSame(Confidence::Medium, $analysis->signal(WebsiteSignal::Email)->confidence);
    }

    public function test_search_login_and_newsletter_forms_are_not_contact_forms(): void
    {
        $analysis = $this->analyze('
            <form role="search"><input type="search" name="q"></form>
            <form><input type="email" name="user"><input type="password" name="pass"><button>Login</button></form>
            <form class="newsletter"><input type="email" name="email"><button>Subscribe</button></form>
        ');

        $this->assertSame(Confidence::Low, $analysis->signal(WebsiteSignal::ContactForm)->confidence);
        $this->assertFalse($analysis->has(WebsiteSignal::ContactForm));
    }

    public function test_an_arabic_contact_form_is_detected(): void
    {
        $analysis = $this->analyze('<form><input name="الاسم"><input type="tel" name="جوال"><textarea name="رسالتك"></textarea></form>');

        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::ContactForm)->confidence);
    }

    public function test_a_booking_platform_is_strong_evidence(): void
    {
        $analysis = $this->analyze('<iframe src="https://calendly.com/smile-dental/consultation"></iframe>');

        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::Booking)->confidence);
        $this->assertSame(['Calendly'], $analysis->signal(WebsiteSignal::Booking)->data['platforms']);
    }

    public function test_an_arabic_booking_button_is_detected(): void
    {
        $analysis = $this->analyze('<a href="/ar/contact">احجز موعدك الآن</a>');

        $this->assertSame(Confidence::Medium, $analysis->signal(WebsiteSignal::Booking)->confidence);
        $this->assertTrue($analysis->has(WebsiteSignal::Booking));
    }

    public function test_booking_mentioned_only_in_text_is_not_treated_as_online_booking(): void
    {
        $analysis = $this->analyze('<p>للحجز يرجى الاتصال بنا هاتفياً</p>');

        $this->assertSame(Confidence::Low, $analysis->signal(WebsiteSignal::Booking)->confidence);
        $this->assertFalse($analysis->has(WebsiteSignal::Booking));
    }

    public function test_own_online_ordering_is_detected(): void
    {
        $this->assertSame(Confidence::High, $this->analyze('<a href="https://alreef.chatfood.io/">Menu</a>')->signal(WebsiteSignal::OnlineOrdering)->confidence);
        $this->assertTrue($this->analyze('<a href="/order-online">اطلب الآن</a>')->has(WebsiteSignal::OnlineOrdering));
        $this->assertTrue($this->analyze('<h1>Chkn</h1>', 'https://order.chkn.sa/')->has(WebsiteSignal::OnlineOrdering));
    }

    public function test_links_to_delivery_apps_are_not_own_ordering(): void
    {
        $analysis = $this->analyze('<a href="https://www.talabat.com/uae/al-reef">Order now</a>');

        $this->assertFalse($analysis->has(WebsiteSignal::OnlineOrdering));
        $this->assertSame(['Talabat'], $analysis->signal(WebsiteSignal::DeliveryApps)->data['platforms']);
    }

    public function test_an_add_to_cart_button_means_a_store_with_a_cart(): void
    {
        $analysis = $this->analyze('<button class="single_add_to_cart_button">أضف إلى السلة</button>');

        $this->assertTrue($analysis->has(WebsiteSignal::Ecommerce));
        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::Cart)->confidence);
    }

    public function test_prices_alone_do_not_make_an_online_store(): void
    {
        $analysis = $this->analyze('<p>Shawarma 15 SAR</p><p>Falafel 8 SAR</p><p>Juice 12 ر.س</p>');

        $this->assertSame(Confidence::Low, $analysis->signal(WebsiteSignal::Ecommerce)->confidence);
        $this->assertFalse($analysis->has(WebsiteSignal::Cart));
    }

    public function test_product_structured_data_alone_is_only_a_hint(): void
    {
        $analysis = $this->analyze('<script type="application/ld+json">{"@type":"Product","name":"Teeth whitening","aggregateRating":{"ratingValue":"4.9"}}</script>');

        $this->assertSame(Confidence::Low, $analysis->signal(WebsiteSignal::Ecommerce)->confidence);
        $this->assertFalse($analysis->has(WebsiteSignal::Ecommerce));
    }

    public function test_links_to_the_business_own_app_are_detected_but_delivery_apps_are_not(): void
    {
        $own = $this->analyze('<a href="https://apps.apple.com/sa/app/saldwich/id1234567890">App Store</a><a href="https://play.google.com/store/apps/details?id=com.saldwich.app">Google Play</a>');
        $delivery = $this->analyze('<a href="https://apps.apple.com/sa/app/talabat-food-grocery/id451001072">Order on Talabat</a>');

        $this->assertTrue($own->has(WebsiteSignal::MobileApp));
        $this->assertSame(['the App Store', 'Google Play'], $own->signal(WebsiteSignal::MobileApp)->data['stores']);
        $this->assertFalse($delivery->has(WebsiteSignal::MobileApp));
    }

    public function test_reads_copyright_years_written_before_the_symbol(): void
    {
        $analysis = $this->analyze('<footer>2019© جميع الحقوق محفوظة</footer>');

        $this->assertSame(2019, $analysis->signal(WebsiteSignal::OutdatedContent)->data['copyright_year']);
        $this->assertTrue($analysis->has(WebsiteSignal::OutdatedContent));
    }

    public function test_a_viewport_without_device_width_is_not_a_mobile_signal(): void
    {
        $this->assertTrue($this->analyze('<meta name="viewport" content="width=device-width, initial-scale=1">')->has(WebsiteSignal::MobileViewport));
        $this->assertSame(Confidence::Low, $this->analyze('<meta name="viewport" content="width=1024">')->signal(WebsiteSignal::MobileViewport)->confidence);
        $this->assertSame(Confidence::None, $this->analyze('<p>No viewport</p>')->signal(WebsiteSignal::MobileViewport)->confidence);
    }

    public function test_detects_platforms_from_markup_headers_and_cookies(): void
    {
        $this->assertSame('Wix', $this->analyze('<p>Hi</p>', headers: ['x-wix-request-id' => '1700000000.123'])->cms);
        $this->assertSame('Squarespace', $this->analyze('<meta name="generator" content="Squarespace">')->cms);
        $this->assertSame('Webflow', $this->analyze('<html data-wf-site="65a1b2c3"><body></body></html>')->cms);
        $this->assertSame('Salla', $this->analyze('<script src="https://cdn.salla.network/js/twilight.js"></script>')->cms);
        $this->assertSame('WordPress', $this->analyze('<link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css"><link href="/wp-includes/css/dist.css">')->cms);
        $this->assertSame('Laravel', $this->analyze('<p>Hi</p>', cookies: ['XSRF-TOKEN', 'smile_dental_session'])->cms);
        $this->assertNull($this->analyze('<p>Plain HTML</p>')->cms);
    }

    public function test_detects_english_content_on_an_english_page(): void
    {
        $analysis = $this->analyze('<html lang="en"><body><p>'.str_repeat('Fresh bread baked every morning in Riyadh. ', 6).'</p></body></html>');

        $this->assertSame('en', $analysis->languages->primary);
        $this->assertTrue($analysis->languages->hasEnglish);
        $this->assertFalse($analysis->languages->hasArabic);
    }

    public function test_detects_a_page_written_in_both_arabic_and_english(): void
    {
        $analysis = $this->analyze('<p>'.str_repeat('Fresh bread baked every morning. ', 6).'</p><p>'.str_repeat('خبز طازج يومياً في الرياض. ', 6).'</p>');

        $this->assertTrue($analysis->languages->hasArabic);
        $this->assertTrue($analysis->languages->hasEnglish);
        $this->assertTrue($analysis->languages->isMultilingual());
    }

    public function test_a_short_coming_soon_page_is_a_placeholder(): void
    {
        $analysis = $this->analyze('<h1>Coming soon</h1><p>Our new website is under construction.</p>');

        $this->assertTrue($analysis->signal(WebsiteSignal::OutdatedContent)->data['placeholder']);
        $this->assertSame(Confidence::High, $analysis->signal(WebsiteSignal::OutdatedContent)->confidence);
    }
}

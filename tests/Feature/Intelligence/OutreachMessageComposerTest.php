<?php

namespace Tests\Feature\Intelligence;

use App\Enums\AuditFailure;
use App\Enums\BusinessCategory;
use App\Enums\OpportunityType;
use App\Intelligence\BusinessEvaluator;
use App\Intelligence\Outreach\OutreachMessage;
use App\Intelligence\Outreach\OutreachMessageComposer;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachMessageComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_without_a_website_gets_a_website_proposal_ready_to_send_on_whatsapp(): void
    {
        $business = $this->evaluated(Business::factory()->create([
            'name' => 'Smile Dental', 'website' => null, 'category' => BusinessCategory::Dentist,
            'phone' => '+966501234567', 'social_links' => ['instagram' => 'https://www.instagram.com/smile'],
        ]));

        $message = $this->compose($business);

        $this->assertSame(implode("\n\n", [
            'السلام عليكم ورحمة الله وبركاته،',
            'وجدت «Smile Dental» على الخريطة ولاحظت أنه لا يوجد لكم موقع إلكتروني خاص، وأن تواجدكم على الإنترنت يقتصر على انستقرام.',
            'يسعدني مساعدتكم في إنشاء موقع احترافي يساعد العملاء على الوصول إليكم عبر بحث قوقل، ويعرض خدماتكم وموقعكم وأوقات العمل، مع إمكانية حجز المواعيد أونلاين.',
            'هل يناسبكم أن أرسل لكم تصوراً مبدئياً مجانياً وبدون أي التزام؟',
            'شكراً لوقتكم، ودمتم بخير.',
        ]), $message->text);
        $this->assertSame(OpportunityType::WebsiteMissing, $message->opportunity);
        $this->assertSame('966501234567', $message->whatsappNumber);
        $this->assertSame('Mobile number from the business profile', $message->numberSource);
        $this->assertFalse($message->numberIsWhatsapp);
        $this->assertSame('https://wa.me/966501234567?text='.rawurlencode($message->text), $message->whatsappUrl());
        $this->assertSame($message->text, rawurldecode(explode('?text=', $message->whatsappUrl())[1]));
    }

    public function test_a_restaurant_on_delivery_apps_is_offered_its_own_ordering_via_the_whatsapp_number_on_its_website(): void
    {
        $business = Business::factory()->create([
            'name' => 'مطعم الريف', 'website' => 'https://alreef.sa', 'category' => BusinessCategory::Restaurant,
            'type' => 'restaurant', 'phone' => '+966501111111',
        ]);
        WebsiteAudit::factory()->create([
            'business_id' => $business, 'url' => 'https://alreef.sa', 'normalized_url' => 'https://alreef.sa',
            'has_whatsapp' => true,
            'signals' => [
                'whatsapp' => ['confidence' => 'high', 'evidence' => [], 'data' => ['numbers' => ['+966555123456']]],
                'delivery_apps' => ['confidence' => 'high', 'evidence' => [], 'data' => ['platforms' => ['Talabat', 'HungerStation']]],
            ],
        ]);

        $message = $this->compose($this->evaluated($business));

        $this->assertStringContainsString('لاحظت أن «مطعم الريف» يعتمد على تطبيقات التوصيل مثل «طلبات» و«هنقرستيشن» لاستقبال الطلبات، وهذه التطبيقات تقتطع عمولة من كل طلب.', $message->text);
        $this->assertStringContainsString('أقترح عليكم نظام طلب أونلاين باسم مطعمكم وبدون عمولات', $message->text);
        $this->assertStringContainsString('ويمكن أن يشمل المشروع أيضاً حجز الطاولات أونلاين.', $message->text);
        $this->assertSame('966555123456', $message->whatsappNumber, 'A number listed as WhatsApp wins over a mobile phone.');
        $this->assertTrue($message->numberIsWhatsapp);
    }

    public function test_a_broken_website_is_mentioned_by_its_address(): void
    {
        $business = Business::factory()->create(['website' => 'https://down.sa', 'category' => BusinessCategory::Dentist]);
        WebsiteAudit::factory()->failed(AuditFailure::DnsFailure)->create(['business_id' => $business, 'url' => 'https://down.sa', 'normalized_url' => 'https://down.sa']);

        $message = $this->compose($this->evaluated($business));

        $this->assertStringContainsString('حاولت زيارة موقعكم (down.sa) ولاحظت أنه لا يعمل حالياً، وهذا قد يُفقدكم عملاء يبحثون عنكم.', $message->text);
    }

    public function test_only_mobile_and_whatsapp_numbers_get_a_whatsapp_link(): void
    {
        $landline = $this->compose($this->evaluated(Business::factory()->create(['website' => null, 'phone' => '+966112345678'])));
        $unified = $this->compose($this->evaluated(Business::factory()->create(['website' => null, 'phone' => '+966920012345'])));
        $uaeMobile = $this->compose($this->evaluated(Business::factory()->create(['website' => null, 'phone' => '+971501234567'])));
        $kuwaitMobile = $this->compose($this->evaluated(Business::factory()->create(['website' => null, 'phone' => '+96565001234'])));

        $this->assertNull($landline->whatsappUrl(), 'The message is still written; it just cannot be sent on WhatsApp.');
        $this->assertNull($unified->whatsappNumber);
        $this->assertSame('971501234567', $uaeMobile->whatsappNumber);
        $this->assertSame('96565001234', $kuwaitMobile->whatsappNumber);
    }

    public function test_a_business_without_opportunities_gets_no_message(): void
    {
        $this->assertNull($this->compose(Business::factory()->create(['website' => null])));
    }

    public function test_introduces_the_sender_when_configured(): void
    {
        config(['intelligence.outreach.sender_name' => 'محمد', 'intelligence.outreach.sender_company' => 'شركة المثال']);
        $business = $this->evaluated(Business::factory()->create(['website' => null, 'phone' => '+966501234567']));

        $message = $this->compose($business);

        $this->assertStringContainsString("وبركاته،\n\nمعكم محمد من شركة المثال، نعمل في تطوير المواقع والتطبيقات وأنظمة الأعمال.\n\n", $message->text);
        $this->assertStringEndsWith("ودمتم بخير.\nمحمد", $message->text);
    }

    private function evaluated(Business $business): Business
    {
        $this->app->make(BusinessEvaluator::class)->evaluate($business);

        return $business->fresh();
    }

    private function compose(Business $business): ?OutreachMessage
    {
        return $this->app->make(OutreachMessageComposer::class)->compose($business);
    }
}

<?php

namespace App\Intelligence\Outreach;

use App\Enums\Confidence;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\CategoryNeeds;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\WebsiteAudit;
use Illuminate\Support\Str;

/**
 * Writes a short, personal first message in Arabic for a business.
 *
 * Deterministic templates, no AI: the message leads with the business's top
 * opportunity, mentions only what was actually found (websites are checked
 * on the homepage only, so absences are phrased as "I did not notice"), and
 * adds up to two further ideas. It ends with a no-commitment question.
 */
final class OutreachMessageComposer
{
    /** Opportunities with a message template, in pitching order when priorities tie. */
    private const SUPPORTED = [
        OpportunityType::WebsiteMissing, OpportunityType::WebsiteRedesign, OpportunityType::OnlineOrdering,
        OpportunityType::OnlineBooking, OpportunityType::Ecommerce, OpportunityType::MobileImprovement,
        OpportunityType::WhatsappIntegration, OpportunityType::MultilingualWebsite,
    ];

    private const MAX_EXTRA_IDEAS = 2;

    /** "Your restaurant", "your clinic"... by category. @var array<string, string> */
    private const BUSINESS_NOUNS = [
        'restaurant' => 'مطعمكم', 'cafe' => 'مقهاكم', 'bakery' => 'مخبزكم', 'hotel' => 'فندقكم', 'clinic' => 'عيادتكم',
        'dentist' => 'عيادتكم', 'pharmacy' => 'صيدليتكم', 'beauty' => 'صالونكم', 'fitness' => 'ناديكم', 'real_estate' => 'مكتبكم',
        'car_dealer' => 'معرضكم', 'car_services' => 'مركزكم', 'car_rental' => 'مكتبكم', 'education' => 'مركزكم',
        'kindergarten' => 'روضتكم', 'legal' => 'مكتبكم', 'accounting' => 'مكتبكم', 'travel' => 'مكتبكم',
        'furniture' => 'متجركم', 'fashion' => 'متجركم', 'jewelry' => 'متجركم', 'electronics' => 'متجركم', 'grocery' => 'متجركم',
        'florist' => 'متجركم', 'laundry' => 'مغسلتكم', 'optician' => 'متجركم', 'veterinary' => 'عيادتكم', 'events' => 'قاعتكم',
        'contractors' => 'شركتكم',
    ];

    /** What customers book, by category. @var array<string, string> */
    private const BOOKING_NOUNS = [
        'hotel' => 'حجز الغرف', 'restaurant' => 'حجز الطاولات', 'cafe' => 'حجز الطاولات', 'car_rental' => 'حجز السيارات',
        'events' => 'حجز القاعة', 'fitness' => 'حجز الحصص', 'travel' => 'إتمام الحجوزات',
    ];

    /** @var array<string, string> */
    private const DELIVERY_APPS = [
        'Talabat' => 'طلبات', 'HungerStation' => 'هنقرستيشن', 'Jahez' => 'جاهز', 'Mrsool' => 'مرسول', 'Careem' => 'كريم',
        'Deliveroo' => 'ديليفرو', 'The Chefz' => 'ذا شفز', 'ToYou' => 'تويو', 'Uber Eats' => 'أوبر إيتس',
        'InstaShop' => 'إنستاشوب', 'Nana' => 'نعناع', 'noon Food' => 'نون فود',
    ];

    /** @var array<string, string> */
    private const SOCIAL_NETWORKS = [
        'instagram' => 'انستقرام', 'facebook' => 'فيسبوك', 'x' => 'إكس (تويتر)', 'tiktok' => 'تيك توك',
        'snapchat' => 'سناب شات', 'linkedin' => 'لينكدإن', 'youtube' => 'يوتيوب', 'telegram' => 'تيليجرام', 'linktree' => 'لينك تري',
    ];

    public function __construct(
        private readonly WhatsAppNumberResolver $numbers,
        private readonly CategoryNeeds $needs,
        private readonly ?string $senderName = null,
        private readonly ?string $senderCompany = null,
    ) {}

    /**
     * The message for a business, or null when it has no opportunity to pitch.
     */
    public function compose(Business $business): ?OutreachMessage
    {
        $business->loadMissing('opportunities.websiteAudit');

        $opportunities = $business->opportunities
            ->filter(fn (Opportunity $opportunity): bool => in_array($opportunity->type, self::SUPPORTED, true))
            ->sort(fn (Opportunity $a, Opportunity $b): int => [$b->priority->rank(), $this->pitchOrder($a->type)] <=> [$a->priority->rank(), $this->pitchOrder($b->type)])
            ->values();

        if ($opportunities->isEmpty()) {
            return null;
        }

        $lead = $opportunities->first()->type;
        $audit = $opportunities->map(fn (Opportunity $opportunity): ?WebsiteAudit => $opportunity->websiteAudit)->filter()->first();

        $ideas = $opportunities->slice(1)
            ->map(fn (Opportunity $opportunity): ?string => $this->idea($opportunity->type, $business, $audit))
            ->filter()
            ->unique()
            ->take(self::MAX_EXTRA_IDEAS)
            ->values()
            ->all();

        $text = implode("\n\n", array_filter([
            'السلام عليكم ورحمة الله وبركاته،',
            $this->introduction(),
            $this->observation($lead, $business, $audit),
            $this->proposal($lead, $business, $audit).($ideas === [] ? '' : ' ويمكن أن يشمل المشروع أيضاً '.$this->arabicList($ideas).'.'),
            'هل يناسبكم أن أرسل لكم تصوراً مبدئياً مجانياً وبدون أي التزام؟',
            $this->closing(),
        ]));

        $contact = $this->numbers->resolve($business, $audit);

        return new OutreachMessage($text, $lead, $contact['number'] ?? null, $contact['source'] ?? null, $contact['confirmed'] ?? false);
    }

    private function introduction(): ?string
    {
        if (blank($this->senderName)) {
            return null;
        }

        return filled($this->senderCompany)
            ? "معكم {$this->senderName} من {$this->senderCompany}، نعمل في تطوير المواقع والتطبيقات وأنظمة الأعمال."
            : "معكم {$this->senderName}، أعمل في تطوير المواقع والتطبيقات وأنظمة الأعمال.";
    }

    private function closing(): string
    {
        return 'شكراً لوقتكم، ودمتم بخير.'.(filled($this->senderName) ? "\n{$this->senderName}" : '');
    }

    /**
     * What was found, stated factually.
     */
    private function observation(OpportunityType $type, Business $business, ?WebsiteAudit $audit): string
    {
        $site = $this->site($business, $audit);

        return match ($type) {
            OpportunityType::WebsiteMissing => $this->missingWebsiteObservation($business),
            OpportunityType::WebsiteRedesign => $this->redesignObservation($audit, $site),
            OpportunityType::OnlineOrdering => $this->orderingObservation($business, $audit, $site),
            OpportunityType::OnlineBooking => "اطلعت على {$site} ولم ألاحظ وجود خدمة {$this->bookingNoun($business)} إلكترونياً"
                .($audit?->has_phone || $audit?->has_whatsapp ? '، ويبدو أن الحجز يتم عبر الاتصال أو الرسائل فقط' : '').'.',
            OpportunityType::Ecommerce => $audit?->has_ecommerce
                ? "اطلعت على {$site} ولاحظت أن المنتجات معروضة، لكن لا يمكن شراؤها مباشرة من الموقع."
                : "اطلعت على {$site} ولم ألاحظ وجود متجر إلكتروني للبيع أونلاين.",
            OpportunityType::MobileImprovement => "اطلعت على {$site} ولاحظت أنه غير مهيأ للعرض على شاشات الجوال، وهي الوسيلة التي يتصفح بها أغلب العملاء اليوم.",
            OpportunityType::WhatsappIntegration => "اطلعت على {$site} ولاحظت أنه لا يوجد زر واتساب للتواصل السريع معكم.",
            OpportunityType::MultilingualWebsite => "اطلعت على {$site} ولاحظت أنه متوفر باللغة ".($audit?->has_arabic ? 'العربية' : 'الإنجليزية').' فقط.',
        };
    }

    /**
     * What is offered for the lead opportunity.
     */
    private function proposal(OpportunityType $type, Business $business, ?WebsiteAudit $audit): string
    {
        return match ($type) {
            OpportunityType::WebsiteMissing => 'يسعدني مساعدتكم في إنشاء موقع احترافي يساعد العملاء على الوصول إليكم عبر بحث قوقل، ويعرض خدماتكم وموقعكم وأوقات العمل'
                .$this->websiteFeature($business).'.',
            OpportunityType::WebsiteRedesign => 'يسعدني مساعدتكم في تطوير الموقع بتصميم عصري وسريع ومتوافق مع الجوال ومحركات البحث.',
            OpportunityType::OnlineOrdering => "أقترح عليكم نظام طلب أونلاين باسم {$this->businessNoun($business)} وبدون عمولات، يستقبل الطلبات مباشرة مع خيارات التوصيل والاستلام والدفع الإلكتروني.",
            OpportunityType::OnlineBooking => "أقترح عليكم نظام حجز إلكتروني يتيح لعملائكم {$this->bookingNoun($business)} في أي وقت، مع تأكيد وتذكير تلقائي، ويخفف الضغط على الاتصالات.",
            OpportunityType::Ecommerce => 'أقترح عليكم متجراً إلكترونياً متكاملاً بسلة مشتريات ودفع إلكتروني آمن، مع لوحة سهلة لإدارة الطلبات والمنتجات.',
            OpportunityType::MobileImprovement => 'يسعدني مساعدتكم في تحسين الموقع ليظهر بشكل احترافي وسهل الاستخدام على جميع الأجهزة.',
            OpportunityType::WhatsappIntegration => 'أقترح إضافة زر واتساب مرتبط بالموقع، فهو يحوّل الزائر إلى عميل بضغطة واحدة.',
            OpportunityType::MultilingualWebsite => $audit?->has_arabic
                ? 'أقترح إضافة نسخة إنجليزية احترافية من الموقع لخدمة المقيمين والزوار.'
                : 'أقترح إضافة نسخة عربية احترافية من الموقع للوصول إلى شريحة أكبر من العملاء.',
        };
    }

    /**
     * A short extra idea for a secondary opportunity.
     */
    private function idea(OpportunityType $type, Business $business, ?WebsiteAudit $audit): ?string
    {
        return match ($type) {
            OpportunityType::OnlineBooking => $this->bookingNoun($business).' أونلاين',
            OpportunityType::OnlineOrdering => 'الطلب أونلاين بدون عمولات',
            OpportunityType::Ecommerce => 'متجراً إلكترونياً',
            OpportunityType::WhatsappIntegration => 'زر واتساب للتواصل المباشر',
            OpportunityType::MultilingualWebsite => $audit?->has_arabic ? 'نسخة إنجليزية من الموقع' : 'نسخة عربية من الموقع',
            OpportunityType::MobileImprovement => 'توافقاً كاملاً مع الجوال',
            OpportunityType::WebsiteRedesign => 'تصميماً أحدث للموقع',
            default => null,
        };
    }

    private function missingWebsiteObservation(Business $business): string
    {
        $networks = array_keys(array_diff_key($business->social_links ?? [], ['whatsapp' => true]));
        $sentence = "وجدت «{$this->displayName($business)}» على الخريطة ولاحظت أنه لا يوجد لكم موقع إلكتروني خاص";

        if ($networks !== []) {
            $sentence .= '، وأن تواجدكم على الإنترنت يقتصر على '
                .$this->arabicList(array_map(fn (string $network): string => self::SOCIAL_NETWORKS[$network] ?? Str::headline($network), $networks));
        }

        return $sentence.'.';
    }

    private function redesignObservation(?WebsiteAudit $audit, string $site): string
    {
        if ($audit?->showsBrokenWebsite()) {
            return "حاولت زيارة {$site} ولاحظت أنه لا يعمل حالياً، وهذا قد يُفقدكم عملاء يبحثون عنكم.";
        }

        if ($audit?->signalData(WebsiteSignal::OutdatedContent)['placeholder'] ?? false) {
            return "زرت {$site} ولاحظت أنه ما زال صفحة مؤقتة دون محتوى.";
        }

        $year = $audit?->signalData(WebsiteSignal::OutdatedContent)['copyright_year'] ?? null;
        $outdated = $audit?->signalConfidence(WebsiteSignal::OutdatedContent)->atLeast(Confidence::Medium) ?? false;

        $issues = array_slice(array_values(array_filter([
            $audit !== null && $audit->is_https === false ? 'عدم وجود اتصال آمن (HTTPS)' : null,
            $audit?->is_https && $audit->ssl_valid === false ? 'عدم صلاحية شهادة الأمان (SSL)' : null,
            $audit?->has_viewport === false ? 'عدم توافقه مع شاشات الجوال' : null,
            $outdated && $year !== null ? "أن آخر تحديث ظاهر للموقع يعود إلى عام {$year}" : null,
        ])), 0, 2);

        return "اطلعت على {$site} ولاحظت بعض النقاط التي تؤثر على تجربة العملاء، مثل "
            .$this->arabicList($issues === [] ? ['قِدم التصميم وغياب بعض العناصر الأساسية'] : $issues).'.';
    }

    private function orderingObservation(Business $business, ?WebsiteAudit $audit, string $site): string
    {
        $apps = $audit?->signalData(WebsiteSignal::DeliveryApps)['platforms'] ?? [];

        if ($apps !== []) {
            return "لاحظت أن «{$this->displayName($business)}» يعتمد على تطبيقات التوصيل مثل "
                .$this->arabicList(array_map(fn (string $app): string => '«'.(self::DELIVERY_APPS[$app] ?? $app).'»', $apps))
                .' لاستقبال الطلبات، وهذه التطبيقات تقتطع عمولة من كل طلب.';
        }

        if ($audit?->signalConfidence(WebsiteSignal::MobileApp)->atLeast(Confidence::Medium)) {
            return "لاحظت أن لديكم تطبيقاً للطلب، لكن {$site} لا يتيح الطلب المباشر لمن لا يملك التطبيق.";
        }

        return "اطلعت على {$site} ولم ألاحظ إمكانية الطلب أونلاين مباشرة منه.";
    }

    /**
     * The most important online capability the category needs, offered with a new website.
     */
    private function websiteFeature(Business $business): string
    {
        $needs = array_filter($this->needs->forBusiness($business), fn (OpportunityPriority $priority): bool => $priority !== OpportunityPriority::Low);
        uasort($needs, fn (OpportunityPriority $a, OpportunityPriority $b): int => $b->rank() <=> $a->rank());

        return match (array_key_first($needs)) {
            OpportunityType::OnlineBooking->value => '، مع إمكانية '.$this->bookingNoun($business).' أونلاين',
            OpportunityType::OnlineOrdering->value => '، مع إمكانية الطلب أونلاين مباشرة',
            OpportunityType::Ecommerce->value => '، مع متجر إلكتروني للبيع أونلاين',
            default => '',
        };
    }

    /**
     * "موقعكم (example.sa)" — the website by its host name.
     */
    private function site(Business $business, ?WebsiteAudit $audit): string
    {
        $host = $business->websiteHost() ?? parse_url((string) $audit?->normalized_url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? "موقعكم ({$host})" : 'موقعكم';
    }

    /**
     * The business name, preferring an Arabic one for an Arabic message.
     */
    private function displayName(Business $business): string
    {
        foreach ([$business->name, $business->alternate_name] as $name) {
            if ($name !== null && preg_match('/\p{Arabic}/u', $name)) {
                return $name;
            }
        }

        return $business->name;
    }

    private function businessNoun(Business $business): string
    {
        return self::BUSINESS_NOUNS[$business->category?->value] ?? 'نشاطكم';
    }

    private function bookingNoun(Business $business): string
    {
        return self::BOOKING_NOUNS[$business->category?->value] ?? 'حجز المواعيد';
    }

    private function pitchOrder(OpportunityType $type): int
    {
        return (int) array_search($type, self::SUPPORTED, true);
    }

    /**
     * "أ"، "أ وب"، "أ، ب وج" — Arabic attaches "و" to the next word.
     *
     * @param  list<string>  $items
     */
    private function arabicList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode('، ', $items).' و'.$last;
    }
}

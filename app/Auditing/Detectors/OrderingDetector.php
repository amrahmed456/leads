<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\HtmlDocument;
use App\Auditing\KeywordMatcher;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Whether customers can order (food, groceries...) directly from the business.
 *
 * Links to delivery apps (Talabat, HungerStation, Jahez...) are reported
 * separately: they show demand for ordering but are not the business's own
 * ordering channel.
 */
final class OrderingDetector implements SignalDetector
{
    use InspectsUrls;

    /** Online ordering systems a business runs under its own brand. @var array<string, string> */
    private const ORDERING_PLATFORMS = [
        'chatfood.io' => 'ChatFood', 'zyda.com' => 'Zyda', 'ordable.co' => 'Ordable', 'getbento.com' => 'BentoBox',
        'toasttab.com' => 'Toast', 'order.online' => 'DoorDash Storefront', 'gloriafood.com' => 'GloriaFood',
        'fbgcdn.com' => 'GloriaFood', 'foodbooking.com' => 'GloriaFood', 'menufy.com' => 'Menufy',
        'square.site' => 'Square Online', 'orderyoyo.com' => 'OrderYOYO',
    ];

    /** Delivery marketplaces. @var array<string, string> */
    private const DELIVERY_APPS = [
        'talabat.com' => 'Talabat', 'hungerstation.com' => 'HungerStation', 'jahez.net' => 'Jahez', 'mrsool.co' => 'Mrsool',
        'careem.com' => 'Careem', 'deliveroo.ae' => 'Deliveroo', 'deliveroo.com' => 'Deliveroo', 'thechefz.co' => 'The Chefz',
        'toyou.io' => 'ToYou', 'ubereats.com' => 'Uber Eats', 'instashop.com' => 'InstaShop', 'nana.sa' => 'Nana',
        'food.noon.com' => 'noon Food',
    ];

    private const CALLS_TO_ACTION = [
        'order online', 'order now', 'order here', 'start your order', 'start order', 'place an order', 'place order',
        'online ordering', 'order delivery', 'order pickup', 'order for delivery',
        'اطلب الآن', 'اطلب أونلاين', 'اطلب الحين', 'اطلب من هنا', 'اطلب عبر', 'الطلب أونلاين', 'طلب أونلاين', 'اطلب',
    ];

    private const ORDERING_PATH = '#/(?:order(?:ing|-online|now)?|online-?order(?:ing)?|اطلب)(?:[/?\#.\-_]|$)#iu';

    private const MENU_LABELS = ['menu', 'our menu', 'view menu', 'منيو', 'المنيو', 'قائمة الطعام'];

    private const MENTIONS = ['delivery', 'pickup', 'pick up', 'takeaway', 'take away', 'order online', 'توصيل', 'استلام من الفرع', 'سفري'];

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        $ordering = new SignalBuilder(WebsiteSignal::OnlineOrdering);
        $delivery = new SignalBuilder(WebsiteSignal::DeliveryApps);

        // The audited page itself may be the ordering site (order.example.sa).
        if (preg_match('/^(?:order|orders|ordering)\./', $document->host()) || preg_match(self::ORDERING_PATH, $this->pathOf($document->url))) {
            $ordering->add(Confidence::Medium, "The website is an ordering site ({$document->host()})");
        }

        foreach ($this->referencedUrls($document) as $url) {
            $app = $this->serviceFor($url, self::DELIVERY_APPS);

            if ($app !== null) {
                $delivery->add(Confidence::High, "Linked to {$app}")->collect('platforms', $app);

                continue;
            }

            $platform = $this->serviceFor($url, self::ORDERING_PLATFORMS);

            if ($platform !== null) {
                $ordering->add(Confidence::High, "Online ordering via {$platform}")->collect('platforms', $platform);
            }
        }

        foreach ($document->links() as $link) {
            if ($this->serviceFor($link['href'], self::DELIVERY_APPS) !== null) {
                continue;
            }

            if (KeywordMatcher::first($link['text'], self::CALLS_TO_ACTION) !== null) {
                $ordering->add(Confidence::Medium, 'Ordering link '.$this->quote($link['text']));
            } elseif ($this->isSameSite($link['href'], $document)
                && (preg_match(self::ORDERING_PATH, $this->pathOf($link['href'])) || preg_match('/^(?:order|orders|ordering)\./', $this->hostOf($link['href'])))) {
                $ordering->add(Confidence::Medium, 'Link to an ordering page: '.$this->hostOf($link['href']).$this->pathOf($link['href']));
            }

            if (KeywordMatcher::first($link['text'], self::MENU_LABELS) !== null) {
                $ordering->set('menu', true);
            }
        }

        foreach ($document->buttons() as $label) {
            if (KeywordMatcher::first($label, self::CALLS_TO_ACTION) !== null) {
                $ordering->add(Confidence::Medium, 'Ordering button '.$this->quote($label));
            }
        }

        $mention = KeywordMatcher::firstInSearchable($document->searchableText(), self::MENTIONS);

        if ($mention !== null) {
            $ordering->hint('Ordering or delivery is only mentioned in the text ('.$this->quote($mention).')');
        }

        return [$ordering->build(), $delivery->build()];
    }
}

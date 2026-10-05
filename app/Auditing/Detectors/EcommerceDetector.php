<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\HtmlDocument;
use App\Auditing\KeywordMatcher;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;

/**
 * Whether the website sells online, and whether it has a cart / checkout.
 */
final class EcommerceDetector implements SignalDetector
{
    use InspectsUrls;

    private const ADD_TO_CART = [
        'add to cart', 'add to bag', 'add to basket', 'buy now',
        'أضف إلى السلة', 'إضافة إلى السلة', 'أضف للسلة', 'أضف إلى العربة', 'اشتر الآن', 'شراء الآن',
    ];

    private const CART_LABELS = [
        'cart', 'shopping cart', 'my cart', 'view cart', 'checkout', 'basket', 'shopping bag',
        'السلة', 'سلة التسوق', 'سلة المشتريات', 'عربة التسوق', 'إتمام الطلب', 'إتمام الشراء',
    ];

    private const CART_PATH = '#/(?:cart|basket|checkout|bag|السلة)(?:[/?\#.\-_]|$)#iu';

    private const SHOP_LABELS = ['shop', 'shop now', 'store', 'online store', 'shop online', 'products', 'متجر', 'المتجر', 'تسوق', 'تسوق الآن', 'منتجاتنا'];

    private const PRICE = '/(?:SAR|AED|QAR|KWD|BHD|OMR|ر\.?\s?س|د\.?\s?إ|ريال|درهم)\s?\d|\d[\d,.]*\s?(?:SAR|AED|QAR|KWD|BHD|OMR|ر\.?\s?س|د\.?\s?إ|ريال|درهم)/u';

    private const PRODUCT_TYPES = ['Product', 'Offer', 'AggregateOffer', 'ProductGroup'];

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        $store = new SignalBuilder(WebsiteSignal::Ecommerce);
        $cart = new SignalBuilder(WebsiteSignal::Cart);

        foreach ($context->technologiesIn('ecommerce') as $technology) {
            $store->add(Confidence::High, "Store platform: {$technology->name}")->collect('platforms', $technology->name);
            $cart->add(Confidence::Medium, "{$technology->name} provides a cart and checkout");
        }

        // Service businesses also mark up "products" (often just for review stars), so this alone is only a hint.
        if (array_intersect($document->jsonLdTypes(), self::PRODUCT_TYPES) !== []
            || strtolower((string) $document->meta('og:type')) === 'product'
            || $document->meta('product:price:amount') !== null) {
            $store->add(Confidence::Low, 'Product structured data');
        }

        foreach ([...array_column($document->links(), 'text'), ...$document->buttons()] as $label) {
            if (KeywordMatcher::first($label, self::ADD_TO_CART) !== null) {
                $store->add(Confidence::Medium, 'Add-to-cart button '.$this->quote($label));
                $cart->add(Confidence::High, 'Add-to-cart button '.$this->quote($label));
            }
        }

        foreach ($document->links() as $link) {
            $cartPath = $this->isSameSite($link['href'], $document) && preg_match(self::CART_PATH, $this->pathOf($link['href']));

            if ($cartPath || KeywordMatcher::first($link['text'], self::CART_LABELS) !== null) {
                $cart->add(Confidence::Medium, 'Cart or checkout link '.$this->quote($link['text'] ?: $this->pathOf($link['href'])));
                $store->add(Confidence::Medium, 'Cart or checkout link');
            } elseif (KeywordMatcher::first($link['text'], self::SHOP_LABELS) !== null) {
                $store->hint('Shop link '.$this->quote($link['text']));
            }
        }

        foreach ($document->forms() as $form) {
            if (preg_match('/add-to-cart|\/cart\b/i', $form['action'].' '.$form['markers'])) {
                $cart->add(Confidence::High, 'Add-to-cart form');
            }
        }

        $prices = (int) preg_match_all(self::PRICE, $document->visibleText());

        if ($prices >= 3) {
            $store->set('price_count', $prices)->hint("{$prices} prices listed");
        }

        return [$store->build(), $cart->build()];
    }
}

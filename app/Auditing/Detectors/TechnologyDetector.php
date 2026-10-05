<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\DetectedTechnology;
use App\Auditing\HtmlDocument;
use App\Enums\Confidence;

/**
 * Identifies the platform and common tools a website uses.
 *
 * Passive only: it reads the page that was already fetched, its response
 * headers and the names of cookies it sets. No extra paths are probed.
 */
final class TechnologyDetector
{
    /**
     * @var list<array{name: string, category: string, generator?: string, html?: string, headers?: array<string, string>, cookies?: string, cookie_pair?: array{0: string, 1: string}}>
     */
    private const SIGNATURES = [
        ['name' => 'Shopify', 'category' => 'ecommerce', 'html' => '#cdn\.shopify\.com|Shopify\.theme|shopify-section#', 'headers' => ['x-shopify-stage' => '/./', 'x-shopid' => '/./', 'powered-by' => '/shopify/i']],
        ['name' => 'Salla', 'category' => 'ecommerce', 'html' => '#cdn\.salla\.(?:network|sa)|assets\.salla\.|window\.salla\b#i'],
        ['name' => 'Zid', 'category' => 'ecommerce', 'html' => '#(?:media|cdn|assets)\.zid\.(?:store|sa)|//zid\.store/#i'],
        ['name' => 'WooCommerce', 'category' => 'ecommerce', 'generator' => '/woocommerce/i', 'html' => '#/plugins/woocommerce/|woocommerce-(?:page|cart|checkout)|wc-cart-fragments#i'],
        ['name' => 'Magento', 'category' => 'ecommerce', 'html' => '#/static/version\d+/|data-mage-init|Magento_Theme|mage/cookies#', 'cookies' => '/^(?:X-Magento-Vary|mage-cache-storage)$/i'],
        ['name' => 'OpenCart', 'category' => 'ecommerce', 'html' => '#index\.php\?route=(?:product|common|checkout|account)/|catalog/view/theme/#i'],
        ['name' => 'PrestaShop', 'category' => 'ecommerce', 'generator' => '/prestashop/i', 'html' => '#/modules/ps_[a-z]+/#i', 'cookies' => '/^PrestaShop-/'],
        ['name' => 'BigCommerce', 'category' => 'ecommerce', 'html' => '#cdn\d*\.bigcommerce\.com#i'],
        ['name' => 'ExpandCart', 'category' => 'ecommerce', 'html' => '#expandcart\.com#i'],
        ['name' => 'Ecwid', 'category' => 'ecommerce', 'html' => '#app\.ecwid\.com/script\.js#i'],
        ['name' => 'Wix', 'category' => 'site_builder', 'generator' => '/wix\.com/i', 'html' => '#static\.wixstatic\.com|static\.parastorage\.com#i', 'headers' => ['x-wix-request-id' => '/./']],
        ['name' => 'Squarespace', 'category' => 'site_builder', 'generator' => '/squarespace/i', 'html' => '#static1\.squarespace\.com|squarespace-cdn\.com#i', 'headers' => ['server' => '/squarespace/i']],
        ['name' => 'Webflow', 'category' => 'site_builder', 'generator' => '/webflow/i', 'html' => '#data-wf-(?:site|page)=|assets\.website-files\.com|cdn\.prod\.website-files\.com#i'],
        ['name' => 'GoDaddy Website Builder', 'category' => 'site_builder', 'generator' => '/go ?daddy|starfield technologies/i', 'html' => '#img\d*\.wsimg\.com#i'],
        ['name' => 'WordPress', 'category' => 'cms', 'generator' => '/wordpress/i', 'html' => '#/wp-(?:content|includes)/#i', 'headers' => ['link' => '#/wp-json/#i']],
        ['name' => 'Joomla', 'category' => 'cms', 'generator' => '/joomla/i', 'html' => '#/media/(?:jui|system)/js/|/components/com_[a-z]+/#i'],
        ['name' => 'Drupal', 'category' => 'cms', 'generator' => '/drupal/i', 'html' => '#Drupal\.settings|data-drupal-|/sites/(?:all|default)/(?:themes|modules|files)/#', 'headers' => ['x-drupal-cache' => '/./', 'x-generator' => '/drupal/i']],
        ['name' => 'Elementor', 'category' => 'page_builder', 'generator' => '/elementor/i', 'html' => '#/plugins/elementor/|class="[^"]*\belementor-#i'],
        ['name' => 'Laravel', 'category' => 'framework', 'html' => '#/livewire/livewire(?:\.min)?\.js|wire:snapshot=#i', 'cookies' => '/^laravel_session$/', 'cookie_pair' => ['/^XSRF-TOKEN$/', '/_session$/']],
        ['name' => 'Next.js', 'category' => 'framework', 'html' => '#/_next/static/|id="__NEXT_DATA__"#', 'headers' => ['x-powered-by' => '/next\.js/i']],
        ['name' => 'Nuxt', 'category' => 'framework', 'html' => '#/_nuxt/|window\.__NUXT__#'],
        ['name' => 'Google Analytics', 'category' => 'analytics', 'html' => '#googletagmanager\.com/gtag/js|google-analytics\.com/(?:analytics|ga)\.js#i'],
        ['name' => 'Google Tag Manager', 'category' => 'analytics', 'html' => '#googletagmanager\.com/gtm\.js|\bGTM-[A-Z0-9]{5,8}\b#'],
        ['name' => 'Meta Pixel', 'category' => 'advertising', 'html' => '#connect\.facebook\.net/[\w]+/fbevents\.js|fbq\(\s*[\'"]init#i'],
        ['name' => 'Snap Pixel', 'category' => 'advertising', 'html' => '#sc-static\.net/scevent\.min\.js|snaptr\(\s*[\'"]init#i'],
        ['name' => 'TikTok Pixel', 'category' => 'advertising', 'html' => '#analytics\.tiktok\.com/i18n/pixel#i'],
    ];

    /** Categories that can name the platform a site runs on, in order of precedence. */
    private const PLATFORM_CATEGORIES = ['ecommerce', 'site_builder', 'cms', 'framework'];

    /** Plugins and widgets that run on top of another platform. */
    private const ADD_ONS = ['WooCommerce', 'Ecwid', 'Elementor'];

    /**
     * @return list<DetectedTechnology>
     */
    public function detect(HtmlDocument $document): array
    {
        $generators = implode(' | ', $document->metaAll('generator'));
        $found = [];

        foreach (self::SIGNATURES as $signature) {
            $match = $this->match($signature, $document, $generators);

            if ($match !== null) {
                $found[] = new DetectedTechnology($signature['name'], $signature['category'], $match[0], $match[1]);
            }
        }

        return $found;
    }

    /**
     * The platform the site is built on: store platform, site builder, CMS or framework.
     *
     * @param  list<DetectedTechnology>  $technologies
     */
    public function platform(array $technologies): ?string
    {
        foreach (self::PLATFORM_CATEGORIES as $category) {
            foreach ($technologies as $technology) {
                if ($technology->category === $category && ! in_array($technology->name, self::ADD_ONS, true)) {
                    return $technology->name;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, category: string, generator?: string, html?: string, headers?: array<string, string>, cookies?: string, cookie_pair?: array{0: string, 1: string}}  $signature
     * @return array{0: Confidence, 1: string}|null
     */
    private function match(array $signature, HtmlDocument $document, string $generators): ?array
    {
        if (isset($signature['generator']) && $generators !== '' && preg_match($signature['generator'], $generators)) {
            return [Confidence::High, 'Meta generator tag'];
        }

        foreach ($signature['headers'] ?? [] as $header => $pattern) {
            $value = $document->header($header);

            if ($value !== null && preg_match($pattern, $value)) {
                return [Confidence::High, "Response header {$header}"];
            }
        }

        foreach ($document->cookies as $cookie) {
            if (isset($signature['cookies']) && preg_match($signature['cookies'], $cookie)) {
                return [Confidence::High, "Cookie {$cookie}"];
            }
        }

        if (isset($signature['cookie_pair']) && $this->cookiesMatch($document->cookies, $signature['cookie_pair'])) {
            return [Confidence::Medium, 'Session and XSRF cookies'];
        }

        if (isset($signature['html']) && preg_match($signature['html'], $document->html)) {
            return [Confidence::Medium, 'Page source'];
        }

        return null;
    }

    /**
     * @param  list<string>  $cookies
     * @param  array{0: string, 1: string}  $patterns
     */
    private function cookiesMatch(array $cookies, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_grep($pattern, $cookies) === []) {
                return false;
            }
        }

        return true;
    }
}

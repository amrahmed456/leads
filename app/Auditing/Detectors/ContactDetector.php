<?php

namespace App\Auditing\Detectors;

use App\Auditing\Data\AnalysisContext;
use App\Auditing\Data\DetectedSignal;
use App\Auditing\HtmlDocument;
use App\Auditing\KeywordMatcher;
use App\Enums\Confidence;
use App\Enums\WebsiteSignal;
use App\Support\Normalization\EmailNormalizer;
use App\Support\Normalization\PhoneNormalizer;
use App\Support\Normalization\SocialLinkNormalizer;
use App\Support\Normalization\Text;
use Illuminate\Support\Str;

/**
 * How visitors can get in touch: phone, e-mail, WhatsApp, a contact form
 * and social profiles.
 */
final class ContactDetector implements SignalDetector
{
    private const WHATSAPP_HOSTS = ['wa.me', 'api.whatsapp.com', 'chat.whatsapp.com', 'web.whatsapp.com', 'whatsapp.com', 'wa.link'];

    private const WHATSAPP_WIDGETS = '/joinchat|ht[-_]ctc|click[-_]to[-_]chat|wa[-_]chat|whatsapp[-_](?:button|widget|chat|float|icon)|wp-whatsapp/i';

    private const WHATSAPP_WORDS = ['whatsapp', 'واتساب', 'واتس اب', 'الواتس'];

    /** @var array<string, string> URL fragment => service */
    private const FORM_SERVICES = [
        'docs.google.com/forms' => 'Google Forms', 'forms.gle' => 'Google Forms', 'typeform.com' => 'Typeform',
        'jotform.com' => 'Jotform', 'hsforms.com' => 'HubSpot', 'hsforms.net' => 'HubSpot', 'tally.so' => 'Tally',
        'formstack.com' => 'Formstack', 'cognitoforms.com' => 'Cognito Forms', 'forms.office.com' => 'Microsoft Forms',
        'zfrmz.com' => 'Zoho Forms',
    ];

    private const FORM_PLUGINS = '/wpcf7|wpforms|gform|elementor-form|ninja-forms|nf-form|fluentform|frm_form|hs-form|contact/';

    private const EMAIL_FIELD = '/e-?mail|بريد|ايميل|إيميل/u';

    private const PHONE_FIELD = '/phone|mobile|\btel\b|whatsapp|جوال|هاتف|رقم/u';

    private const NAME_FIELD = '/name|الاسم|اسم/u';

    private const SEARCH_FIELD = '/^(?:s|q|query|search|keywords?)\b/';

    private const IGNORED_EMAIL_DOMAINS = ['example.com', 'example.org', 'domain.com', 'email.com', 'yourdomain.com', 'sentry.io', 'wixpress.com'];

    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'css', 'js'];

    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly EmailNormalizer $emails,
        private readonly SocialLinkNormalizer $socialLinks,
    ) {}

    public function detect(HtmlDocument $document, AnalysisContext $context): array
    {
        return [
            $this->phone($document, $context),
            $this->email($document),
            $this->whatsapp($document),
            $this->contactForm($document),
            $this->socialProfiles($document),
        ];
    }

    private function phone(HtmlDocument $document, AnalysisContext $context): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::Phone);

        foreach ($document->links() as $link) {
            if (str_starts_with(strtolower($link['href']), 'tel:')) {
                $number = $this->phones->normalize(rawurldecode(substr($link['href'], 4)), $context->country);

                if ($number !== null) {
                    $signal->add(Confidence::High, "Click-to-call link: {$number}")->collect('numbers', $number);
                }
            }
        }

        if ($signal->confidence() !== Confidence::High) {
            preg_match_all('/(?<!\d)(?:\+|00)?\d[\d \-.()]{6,16}\d(?!\d)/', Text::asciiDigits($document->visibleText()), $matches);

            foreach (array_slice(array_unique($matches[0]), 0, 50) as $candidate) {
                $number = $this->looksLikePhone($candidate) ? $this->phones->normalize($candidate, $context->country) : null;

                if ($number !== null && str_starts_with($number, '+')) {
                    $signal->add(Confidence::Medium, "Phone number in the page text: {$number}")->collect('numbers', $number);
                }
            }
        }

        return $signal->build();
    }

    private function looksLikePhone(string $candidate): bool
    {
        $digits = strlen((string) preg_replace('/\D/', '', $candidate));
        $candidate = trim($candidate);

        // Years ("2019 - 2024") and dates ("2024.10.05") are not phone numbers.
        return $digits >= 8 && $digits <= 15
            && ! preg_match('/^(?:19|20)\d{2}\s*[-–.\/]\s*(?:19|20)\d{2}$/', $candidate)
            && ! preg_match('/^(?:(?:19|20)\d{2}[.\-\/]\d{1,2}[.\-\/]\d{1,2}|\d{1,2}[.\-\/]\d{1,2}[.\-\/](?:19|20)\d{2})$/', $candidate);
    }

    private function email(HtmlDocument $document): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::Email);

        foreach ($document->links() as $link) {
            if (str_starts_with(strtolower($link['href']), 'mailto:')) {
                $email = $this->emails->normalize(rawurldecode($link['href']));

                if ($email !== null) {
                    $signal->add(Confidence::High, "E-mail link: {$email}")->collect('addresses', $email);
                }
            } elseif (str_contains($link['href'], '/cdn-cgi/l/email-protection')) {
                $signal->add(Confidence::Medium, 'E-mail address (protected by Cloudflare)');
            }
        }

        if ($document->exists('//*[@data-cfemail]')) {
            $signal->add(Confidence::Medium, 'E-mail address (protected by Cloudflare)');
        }

        if ($signal->confidence() !== Confidence::High) {
            preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $document->visibleText(), $matches);

            foreach (array_slice(array_unique($matches[0]), 0, 20) as $candidate) {
                $email = $this->emails->normalize($candidate);

                if ($email !== null && $this->isRealAddress($email)) {
                    $signal->add(Confidence::Medium, "E-mail address in the page text: {$email}")->collect('addresses', $email);
                }
            }
        }

        return $signal->build();
    }

    private function isRealAddress(string $email): bool
    {
        $domain = Str::after($email, '@');

        return ! in_array(Str::afterLast($domain, '.'), self::ASSET_EXTENSIONS, true)
            && ! collect(self::IGNORED_EMAIL_DOMAINS)->contains(fn (string $ignored): bool => $domain === $ignored || str_ends_with($domain, '.'.$ignored));
    }

    private function whatsapp(HtmlDocument $document): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::Whatsapp);

        foreach ($document->links() as $link) {
            $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($link['href'], PHP_URL_HOST)));

            if (! str_starts_with(strtolower($link['href']), 'whatsapp:') && ! in_array($host, self::WHATSAPP_HOSTS, true)) {
                continue;
            }

            $number = $this->whatsappNumber($link['href']);
            $signal->add(Confidence::High, $number !== null ? "WhatsApp link: wa.me/{$number}" : 'WhatsApp link');

            if ($number !== null) {
                $signal->collect('numbers', '+'.$number);
            }
        }

        if (preg_match(self::WHATSAPP_WIDGETS, $document->html)) {
            $signal->add(Confidence::Medium, 'WhatsApp chat widget');
        }

        if (KeywordMatcher::firstInSearchable($document->searchableText(), self::WHATSAPP_WORDS) !== null) {
            $signal->hint('WhatsApp is mentioned in the text');
        }

        return $signal->build();
    }

    private function whatsappNumber(string $href): ?string
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        $digits = (string) preg_replace('/\D/', '', is_string($query['phone'] ?? null) ? $query['phone'] : (string) parse_url($href, PHP_URL_PATH));

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    private function contactForm(HtmlDocument $document): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::ContactForm);

        foreach ($document->forms() as $form) {
            $fields = array_values(array_filter(
                $form['fields'],
                fn (array $field): bool => ! in_array($field['type'], ['hidden', 'submit', 'button', 'reset', 'image'], true),
            ));
            $types = array_column($fields, 'type');
            $names = implode(' | ', array_column($fields, 'name'));

            $isLogin = in_array('password', $types, true);
            $isSearch = str_contains($form['markers'], 'search') || in_array('search', $types, true)
                || (count($fields) === 1 && preg_match(self::SEARCH_FIELD, $fields[0]['name']));

            if ($isLogin || $isSearch) {
                continue;
            }

            $hasMessage = in_array('textarea', $types, true);
            $hasEmail = in_array('email', $types, true) || preg_match(self::EMAIL_FIELD, $names) === 1;
            $hasPhone = in_array('tel', $types, true) || preg_match(self::PHONE_FIELD, $names) === 1;
            $hasName = preg_match(self::NAME_FIELD, $names) === 1;

            if ($hasMessage && ($hasEmail || $hasPhone || $hasName)) {
                $signal->add(Confidence::High, 'Contact form with a message field');
            } elseif (count($fields) >= 2 && preg_match(self::FORM_PLUGINS, $form['markers'])) {
                $signal->add(Confidence::High, 'Contact form');
            } elseif (count($fields) >= 3 && ($hasEmail || $hasPhone)) {
                $signal->add(Confidence::Medium, 'Form collecting contact details');
            } elseif (count($fields) === 1 && $hasEmail) {
                $signal->hint('Newsletter sign-up form only');
            }
        }

        foreach ([...$document->urls('iframe', 'src'), ...array_column($document->links(), 'href')] as $url) {
            foreach (self::FORM_SERVICES as $fragment => $service) {
                if (str_contains(strtolower($url), $fragment)) {
                    $signal->add(Confidence::Medium, "{$service} form");
                }
            }
        }

        return $signal->build();
    }

    private function socialProfiles(HtmlDocument $document): DetectedSignal
    {
        $signal = new SignalBuilder(WebsiteSignal::SocialProfiles);
        $profiles = [];

        foreach ($document->links() as $link) {
            $network = $this->socialLinks->networkFor($link['href']);

            if ($network === null || $network === 'whatsapp' || preg_match('#/(?:sharer|share|intent/tweet|dialog/share|shareArticle)#i', $link['href'])) {
                continue;
            }

            $signal->add(Confidence::High, Str::headline($network).' profile linked');
            $profiles[$network] ??= mb_substr($link['href'], 0, 300);
        }

        return $signal->set('profiles', $profiles)->build();
    }
}

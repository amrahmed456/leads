<?php

namespace App\Support\Normalization;

use App\Enums\Country;

final class SocialLinkNormalizer
{
    /** @var array<string, list<string>> network => domains */
    private const DOMAINS = [
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'instagram' => ['instagram.com', 'instagr.am'],
        'x' => ['x.com', 'twitter.com'],
        'tiktok' => ['tiktok.com'],
        'snapchat' => ['snapchat.com'],
        'linkedin' => ['linkedin.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'whatsapp' => ['wa.me', 'whatsapp.com'],
        'telegram' => ['t.me', 'telegram.me'],
        'linktree' => ['linktr.ee'],
    ];

    /** @var array<string, string> Profile URL templates for networks where a bare handle is unambiguous. */
    private const PROFILE_URLS = [
        'facebook' => 'https://www.facebook.com/%s',
        'instagram' => 'https://www.instagram.com/%s',
        'x' => 'https://x.com/%s',
        'tiktok' => 'https://www.tiktok.com/@%s',
        'snapchat' => 'https://www.snapchat.com/add/%s',
        'telegram' => 'https://t.me/%s',
    ];

    public function __construct(
        private readonly UrlNormalizer $urls,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * @return list<string>
     */
    public static function networks(): array
    {
        return array_keys(self::DOMAINS);
    }

    /**
     * The social network a URL belongs to, or null for ordinary websites.
     */
    public function networkFor(string $url): ?string
    {
        $host = $this->urls->host($url);

        if ($host === null) {
            return null;
        }

        foreach (self::DOMAINS as $network => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $network;
                }
            }
        }

        return null;
    }

    /**
     * Turn URLs or handles into canonical profile URLs, dropping invalid ones.
     *
     * @param  array<string, mixed>  $links  network => URL or handle
     * @return array<string, string> network => URL
     */
    public function normalize(array $links, ?Country $country = null): array
    {
        $normalized = [];

        foreach ($links as $network => $value) {
            if (! is_string($value) || ! array_key_exists($network, self::DOMAINS)) {
                continue;
            }

            $url = $this->normalizeOne($network, trim($value), $country);

            if ($url !== null) {
                $normalized[$network] = $url;
            }
        }

        return $normalized;
    }

    private function normalizeOne(string $network, string $value, ?Country $country): ?string
    {
        if ($value === '') {
            return null;
        }

        // WhatsApp is usually given as a phone number.
        if ($network === 'whatsapp' && ! preg_match('/[a-z]/i', $value)) {
            $phone = $this->phones->normalize($value, $country);

            return $phone !== null && str_starts_with($phone, '+') ? 'https://wa.me/'.substr($phone, 1) : null;
        }

        if (str_contains($value, '/') || preg_match('/^www\./i', $value)) {
            $url = $this->urls->normalize($value);

            return $url !== null && $this->networkFor($url) === $network ? $url : null;
        }

        $handle = ltrim($value, '@');

        if (isset(self::PROFILE_URLS[$network]) && preg_match('/^[\p{L}\p{N}._\-]{1,100}$/u', $handle)) {
            return sprintf(self::PROFILE_URLS[$network], $handle);
        }

        return null;
    }
}

<?php

namespace App\Support\Normalization;

final class UrlNormalizer
{
    /** Query parameters that only carry tracking information. */
    private const TRACKING_PARAMETERS = ['fbclid', 'gclid', 'igshid', 'igsh', 'mc_cid', 'mc_eid', 'si'];

    /** Schemes that never point to a website. */
    private const NON_WEB_SCHEMES = ['mailto', 'tel', 'sms', 'javascript', 'data', 'file', 'ftp', 'whatsapp', 'geo'];

    /**
     * Normalize a website URL: add a missing scheme, lowercase the host, drop
     * credentials, fragments, default ports and tracking parameters. Returns
     * null for values that are not usable http(s) URLs.
     */
    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Several URLs in one value: keep the first.
        $value = (string) (preg_split('/[;\s]+/', $value)[0] ?? '');

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $value, $matches)
            && in_array(strtolower($matches[1]), self::NON_WEB_SCHEMES, true)) {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        } elseif (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = rtrim(mb_strtolower($parts['host']), '.');

        if (! $this->isValidHost($host)) {
            return null;
        }

        $port = '';
        if (isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))) {
            $port = ':'.$parts['port'];
        }

        $path = $parts['path'] ?? '';
        $query = $this->withoutTracking($parts['query'] ?? '');

        if ($path === '/' && $query === '') {
            $path = '';
        }

        $url = $scheme.'://'.$host.$port.$path.($query !== '' ? '?'.$query : '');

        return mb_strlen($url) <= 500 ? $url : null;
    }

    /**
     * The host of a URL without a leading "www.".
     */
    public function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? (string) preg_replace('/^www\./', '', mb_strtolower($host))
            : null;
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || str_contains($host, '..') || str_starts_with($host, '.') || str_starts_with($host, '-')) {
            return false;
        }

        if (! preg_match('/^[\p{L}\p{N}.\-]+$/u', $host)) {
            return false;
        }

        // Requires a dotted name with an alphabetic (or punycode) TLD; rejects bare IPs and "localhost".
        return (bool) preg_match('/\.(?:\p{L}{2,}|xn--[a-z0-9\-]+)$/u', $host);
    }

    private function withoutTracking(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = array_filter(explode('&', $query), function (string $pair): bool {
            $key = strtolower(urldecode(explode('=', $pair, 2)[0]));

            return $key !== ''
                && ! str_starts_with($key, 'utm_')
                && ! in_array($key, self::TRACKING_PARAMETERS, true);
        });

        return implode('&', $pairs);
    }
}

<?php

namespace App\Support\Normalization;

final class Domain
{
    /** Second-level labels under which domains are registered (example.com.sa). */
    private const SECOND_LEVEL = ['com', 'net', 'org', 'gov', 'edu', 'co', 'ac', 'med', 'sch', 'mil', 'biz'];

    /**
     * The registrable domain of a URL or host: "https://riyadh.example.com.sa/x"
     * → "example.com.sa". Null for values without a usable host.
     */
    public static function registrable(?string $urlOrHost): ?string
    {
        $value = trim((string) $urlOrHost);

        if ($value === '') {
            return null;
        }

        $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : explode('/', $value, 2)[0];

        if (! is_string($host) || $host === '') {
            return null;
        }

        $labels = array_values(array_filter(explode('.', rtrim(mb_strtolower($host), '.'))));

        if (count($labels) < 2) {
            return null;
        }

        $keep = count($labels) >= 3 && in_array($labels[count($labels) - 2], self::SECOND_LEVEL, true) && strlen($labels[count($labels) - 1]) === 2
            ? 3
            : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    /**
     * Whether the domain is, or is under, one of the listed domains or suffixes.
     *
     * @param  list<string>  $domains  e.g. ["talabat.com"] or suffixes like [".gov.sa"]
     */
    public static function matchesAny(?string $urlOrHost, array $domains): ?string
    {
        $host = str_contains((string) $urlOrHost, '://') ? parse_url((string) $urlOrHost, PHP_URL_HOST) : $urlOrHost;
        $host = is_string($host) ? (string) preg_replace('/^www\./', '', mb_strtolower(rtrim($host, '.'))) : '';

        if ($host === '') {
            return null;
        }

        foreach ($domains as $domain) {
            $domain = mb_strtolower($domain);

            if (str_starts_with($domain, '.') ? str_ends_with($host, $domain) : ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return $domain;
            }
        }

        return null;
    }
}

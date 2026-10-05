<?php

namespace App\Auditing\Detectors;

use App\Auditing\HtmlDocument;

trait InspectsUrls
{
    /**
     * The service a URL belongs to, given a map of "domain[/path]" => name.
     * A domain also matches its subdomains (e.g. "book.calendly.com").
     *
     * @param  array<string, string>  $services
     */
    private function serviceFor(string $url, array $services): ?string
    {
        $host = $this->hostOf($url);

        if ($host === '') {
            return null;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach ($services as $pattern => $name) {
            [$domain, $prefix] = array_pad(explode('/', $pattern, 2), 2, null);

            $domainMatches = $host === $domain || str_ends_with($host, '.'.$domain);

            if ($domainMatches && ($prefix === null || str_starts_with(ltrim($path, '/'), $prefix))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether a link stays on the audited website (including its subdomains).
     */
    private function isSameSite(string $url, HtmlDocument $document): bool
    {
        $host = $this->hostOf($url);
        $site = $document->host();

        return $host !== '' && $site !== '' && ($host === $site || str_ends_with($host, '.'.$site) || str_ends_with($site, '.'.$host));
    }

    private function hostOf(string $url): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /**
     * Decoded path of a URL, for matching paths such as /booking or /حجز.
     */
    private function pathOf(string $url): string
    {
        return rawurldecode((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * Every URL the page links to or embeds.
     *
     * @return list<string>
     */
    private function referencedUrls(HtmlDocument $document): array
    {
        return [
            ...array_column($document->links(), 'href'),
            ...$document->urls('iframe', 'src'),
            ...$document->urls('script', 'src'),
        ];
    }

    private function quote(string $label): string
    {
        return '“'.mb_strimwidth($label, 0, 60, '…').'”';
    }
}

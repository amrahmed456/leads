<?php

namespace App\Auditing\Data;

/**
 * The HTTP response for a fetched homepage, after redirects.
 */
final readonly class FetchResult
{
    /**
     * @param  array<string, string>  $headers  Lower-cased header names, first value
     * @param  list<string>  $cookies  Names of cookies set by the response
     * @param  string  $body  At most the configured number of bytes
     * @param  list<string>  $redirects  URLs followed, in order
     * @param  bool  $sslValid  False when the certificate had to be ignored to read the page
     * @param  bool  $usedHttpFallback  True when HTTPS failed and the page was read over HTTP
     */
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public int $status,
        public array $headers,
        public array $cookies,
        public string $body,
        public bool $truncated,
        public int $responseTimeMs,
        public array $redirects,
        public bool $sslValid = true,
        public bool $usedHttpFallback = false,
    ) {}

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->finalUrl), 'https://');
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->headers['content-type'] ?? '')[0]));
    }

    public function isHtml(): bool
    {
        $type = $this->contentType();

        if ($type === 'text/html' || $type === 'application/xhtml+xml') {
            return true;
        }

        // Missing or generic content type: look at the document itself.
        return ($type === '' || $type === 'text/plain' || $type === 'application/octet-stream')
            && preg_match('/^\s*(?:<!doctype\s+html|<html|<head|<meta|<!--)/i', substr($this->body, 0, 512)) === 1;
    }

    /**
     * Character set declared by the response header or the page itself.
     */
    public function charset(): ?string
    {
        if (preg_match('/charset\s*=\s*["\']?([\w\-]+)/i', $this->headers['content-type'] ?? '', $matches)) {
            return strtolower($matches[1]);
        }

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w\-]+)/i', substr($this->body, 0, 4096), $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}

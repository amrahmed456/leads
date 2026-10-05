<?php

namespace App\Auditing;

use App\Auditing\Data\FetchResult;
use App\Auditing\Exceptions\WebsiteFetchException;
use App\Enums\AuditFailure;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a single business homepage within strict limits.
 *
 * - Every URL, including each redirect target, passes the UrlGuard (no
 *   private networks), and the connection is pinned to the checked address.
 * - Redirects are followed manually up to max_redirects.
 * - time_budget caps the total time across redirects and fallbacks.
 * - Only max_bytes of the body are kept; nothing is stored.
 * - An invalid certificate is tolerated once so the page can still be
 *   analyzed (and is reported as invalid); an HTTPS site that cannot be
 *   reached at all is tried once over plain HTTP.
 *
 * At most a handful of requests are made per audit; there is no crawling.
 */
final class WebsiteFetcher
{
    /** cURL error codes caused by TLS or certificate problems. */
    private const SSL_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * @param  array{user_agent: string, connect_timeout: int, timeout: int, time_budget: int, max_redirects: int, max_bytes: int, allow_invalid_ssl: bool, http_fallback: bool}  $options
     */
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly array $options,
    ) {}

    /**
     * @throws WebsiteFetchException
     */
    public function fetch(string $url): FetchResult
    {
        $deadline = microtime(true) + $this->options['time_budget'];

        try {
            return $this->follow($url, $deadline);
        } catch (WebsiteFetchException $e) {
            $failure = $e;
        }

        if ($failure->failure === AuditFailure::SslError && $this->options['allow_invalid_ssl']) {
            try {
                return $this->follow($url, $deadline, verifySsl: false);
            } catch (WebsiteFetchException) {
                // Fall through to the HTTP fallback.
            }
        }

        $unreachableOverHttps = in_array($failure->failure, [AuditFailure::ConnectionFailed, AuditFailure::Timeout, AuditFailure::SslError], true);

        if ($this->options['http_fallback'] && $unreachableOverHttps && str_starts_with(strtolower($url), 'https://')) {
            try {
                return $this->follow('http://'.substr($url, 8), $deadline, usedHttpFallback: true);
            } catch (WebsiteFetchException) {
                // Report the HTTPS failure: it describes the address that is listed.
            }
        }

        throw $failure;
    }

    /**
     * @throws WebsiteFetchException
     */
    private function follow(string $url, float $deadline, bool $verifySsl = true, bool $usedHttpFallback = false): FetchResult
    {
        $started = microtime(true);
        $redirects = [];
        $current = $url;

        while (true) {
            $response = $this->request($current, $deadline, $verifySsl);
            $location = $response->redirect() ? trim((string) $response->header('Location')) : '';

            if ($location === '') {
                break;
            }

            if (count($redirects) >= $this->options['max_redirects']) {
                throw new WebsiteFetchException(
                    AuditFailure::TooManyRedirects,
                    AuditFailure::TooManyRedirects->label().": {$url}",
                    finalUrl: $current,
                );
            }

            $current = UrlNormalizer::resolveReference($current, $location);
            $redirects[] = $current;
        }

        $body = $response->body();

        return new FetchResult(
            requestedUrl: $url,
            finalUrl: $current,
            status: $response->status(),
            headers: $this->headers($response),
            cookies: $this->cookieNames($response),
            body: substr($body, 0, $this->options['max_bytes']),
            truncated: strlen($body) > $this->options['max_bytes'],
            responseTimeMs: (int) round((microtime(true) - $started) * 1000),
            redirects: $redirects,
            sslValid: $verifySsl,
            usedHttpFallback: $usedHttpFallback,
        );
    }

    /**
     * @throws WebsiteFetchException
     */
    private function request(string $url, float $deadline, bool $verifySsl): Response
    {
        $remaining = (int) floor($deadline - microtime(true));

        if ($remaining < 1) {
            throw new WebsiteFetchException(AuditFailure::Timeout, AuditFailure::Timeout->label().": {$url}", finalUrl: $url);
        }

        $target = $this->guard->resolve($url);
        $address = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        $abortAfterBytes = $this->options['max_bytes'] * 4;

        $options = [
            // Connect to the address the guard checked, so DNS cannot change in between.
            'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$address}"]],
            // Abort downloads far beyond what is analyzed (e.g. a video or a huge page).
            'progress' => fn (int $expected, int $downloaded): bool => $downloaded > $abortAfterBytes,
        ];

        if (! $verifySsl) {
            $options['verify'] = false;
        }

        try {
            return Http::withUserAgent($this->options['user_agent'])
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'ar,en;q=0.8',
                ])
                ->connectTimeout(min($this->options['connect_timeout'], $remaining))
                ->timeout(min($this->options['timeout'], $remaining))
                ->withoutRedirecting()
                ->withOptions($options)
                ->get($target['url']);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($e, $url);
        }
    }

    private function connectionFailure(ConnectionException $e, string $url): WebsiteFetchException
    {
        $code = preg_match('/cURL error (\d+)/', $e->getMessage(), $matches) ? (int) $matches[1] : null;

        $failure = match (true) {
            $code === 6 => AuditFailure::DnsFailure,
            $code === 28 => AuditFailure::Timeout,
            $code === 47 => AuditFailure::TooManyRedirects,
            $code === 42, str_contains($e->getMessage(), 'aborted by the progress callback') => AuditFailure::TooLarge,
            in_array($code, self::SSL_ERRORS, true) => AuditFailure::SslError,
            default => AuditFailure::ConnectionFailed,
        };

        return new WebsiteFetchException($failure, "{$failure->label()}: {$url}", finalUrl: $url, previous: $e);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = mb_substr((string) ($values[0] ?? ''), 0, 500);
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function cookieNames(Response $response): array
    {
        $names = [];

        foreach ($response->headers() as $name => $values) {
            if (strtolower($name) !== 'set-cookie') {
                continue;
            }

            foreach ($values as $cookie) {
                $names[] = trim(explode('=', (string) $cookie, 2)[0]);
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}

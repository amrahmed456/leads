<?php

namespace App\Auditing;

use App\Auditing\Contracts\HostResolver;
use App\Auditing\Exceptions\WebsiteFetchException;
use App\Enums\AuditFailure;

/**
 * Decides whether a URL may be fetched.
 *
 * Website addresses come from public map data, so they are untrusted: a
 * listing could point at 127.0.0.1, a private network or a cloud metadata
 * address. Only http(s) URLs whose host resolves exclusively to public IP
 * addresses are allowed, and the caller pins the connection to the checked
 * address so the answer cannot change between the check and the request.
 */
final class UrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     *
     * @throws WebsiteFetchException
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw $this->failure(AuditFailure::InvalidUrl, $url);
        }

        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower(rtrim($parts['host'], '.')), '[]');

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            throw $this->failure(AuditFailure::InvalidUrl, $url);
        }

        $asciiHost = $this->toAscii($host) ?? throw $this->failure(AuditFailure::InvalidUrl, $url);
        $addresses = filter_var($asciiHost, FILTER_VALIDATE_IP) ? [$asciiHost] : $this->resolver->resolve($asciiHost);

        if ($addresses === []) {
            throw $this->failure(AuditFailure::DnsFailure, $url);
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw $this->failure(AuditFailure::BlockedHost, $url);
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [
            'url' => $scheme.'://'.(str_contains($asciiHost, ':') ? "[{$asciiHost}]" : $asciiHost)
                .(isset($parts['port']) ? ':'.$port : '')
                .($parts['path'] ?? '')
                .(isset($parts['query']) ? '?'.$parts['query'] : ''),
            'host' => $asciiHost,
            'port' => $port,
            'ip' => $addresses[0],
        ];
    }

    private function toAscii(string $host): ?string
    {
        if (preg_match('/^[a-z0-9.\-]+$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // Internationalized (e.g. Arabic) domain names.
        if (! function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : $ascii;
    }

    private function isPublic(string $address): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }

        return filter_var($address, FILTER_VALIDATE_IP, $flags) !== false;
    }

    private function failure(AuditFailure $failure, string $url): WebsiteFetchException
    {
        return new WebsiteFetchException($failure, "{$failure->label()}: {$url}", finalUrl: $url);
    }
}

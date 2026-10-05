<?php

namespace App\Enums;

/**
 * Why a website audit could not analyze the homepage.
 */
enum AuditFailure: string
{
    case InvalidUrl = 'invalid_url';
    case BlockedHost = 'blocked_host';
    case DnsFailure = 'dns_failure';
    case ConnectionFailed = 'connection_failed';
    case Timeout = 'timeout';
    case SslError = 'ssl_error';
    case TooManyRedirects = 'too_many_redirects';
    case AccessDenied = 'access_denied';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case NotFound = 'not_found';
    case ClientError = 'client_error';
    case NotHtml = 'not_html';
    case TooLarge = 'too_large';
    case Unexpected = 'unexpected';

    public function label(): string
    {
        return match ($this) {
            self::InvalidUrl => 'The website address is not a valid URL',
            self::BlockedHost => 'The website points to a private or reserved network address',
            self::DnsFailure => 'The domain name does not resolve',
            self::ConnectionFailed => 'The server refused or dropped the connection',
            self::Timeout => 'The website did not respond in time',
            self::SslError => 'The secure connection could not be established',
            self::TooManyRedirects => 'The website redirects too many times',
            self::AccessDenied => 'The website blocked the request (HTTP 401/403)',
            self::RateLimited => 'The website rate-limited the request (HTTP 429)',
            self::ServerError => 'The website returned a server error (HTTP 5xx)',
            self::NotFound => 'The homepage was not found (HTTP 404/410)',
            self::ClientError => 'The website rejected the request (HTTP 4xx)',
            self::NotHtml => 'The address does not serve an HTML page',
            self::TooLarge => 'The homepage is too large to analyze',
            self::Unexpected => 'The audit stopped unexpectedly',
        };
    }

    /**
     * Temporary conditions worth retrying later.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::DnsFailure, self::ConnectionFailed, self::Timeout, self::RateLimited, self::ServerError => true,
            default => false,
        };
    }

    /**
     * Whether the failure shows the website itself is down or broken, as
     * opposed to the audit merely being unable to look (blocked, rate limited).
     */
    public function meansWebsiteIsBroken(): bool
    {
        return match ($this) {
            self::DnsFailure, self::ConnectionFailed, self::Timeout, self::SslError,
            self::TooManyRedirects, self::ServerError, self::NotFound => true,
            default => false,
        };
    }
}

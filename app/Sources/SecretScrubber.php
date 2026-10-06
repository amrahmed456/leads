<?php

namespace App\Sources;

/**
 * Removes credentials from text before it is stored, logged or displayed.
 * HTTP client errors include the request URL, which can carry an API key.
 */
final class SecretScrubber
{
    private const QUERY_SECRET = '/\b(api[_-]?key|apikey|key|token|access_token|username|password|secret)=([^&\s"\'<>]+)/i';

    /**
     * @param  list<string>  $secrets  Known credential values
     */
    public static function scrub(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return (string) preg_replace(self::QUERY_SECRET, '$1=[redacted]', $text);
    }

    /**
     * Masked form of a credential for display: only the last 4 characters.
     */
    public static function mask(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return strlen($secret) <= 4 ? '••••' : '••••••'.substr($secret, -4);
    }
}

<?php

namespace App\Discovery\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when discovery cannot complete.
 *
 * Retryable failures (timeouts, rate limits, provider outages) are retried by
 * the queue; permanent failures (unknown location, rejected query) fail the
 * campaign immediately because retrying would not help.
 */
class DiscoveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        return new self($message, retryable: true, previous: $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, retryable: false, previous: $previous);
    }
}

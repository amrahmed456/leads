<?php

namespace App\Auditing\Exceptions;

use App\Enums\AuditFailure;
use RuntimeException;
use Throwable;

/**
 * The homepage could not be fetched, or did not return a usable HTML page.
 */
final class WebsiteFetchException extends RuntimeException
{
    public function __construct(
        public readonly AuditFailure $failure,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $finalUrl = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->failure->isRetryable();
    }
}

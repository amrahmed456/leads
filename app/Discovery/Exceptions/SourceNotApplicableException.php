<?php

namespace App\Discovery\Exceptions;

/**
 * The source has nothing to say about this campaign (e.g. no imported
 * Overture extract covers the area). The source run is skipped, not failed.
 */
class SourceNotApplicableException extends DiscoveryException
{
    public function __construct(string $message)
    {
        parent::__construct($message, retryable: false);
    }
}

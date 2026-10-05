<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never call real external services, nor wait between HTTP retries.
        Http::preventStrayRequests();
        Sleep::fake();

        // Page tests should not depend on compiled frontend assets.
        $this->withoutVite();

        // Keep test runs out of the real discovery log (log events are still dispatched).
        config(['logging.channels.discovery.channels' => ['null']]);
    }

    /**
     * @return array<mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/Fixtures/{$name}"), true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

namespace Tests;

use App\Auditing\Contracts\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fakes\StaticHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never call real external services (HTTP or DNS), nor wait between retries.
        Http::preventStrayRequests();
        Sleep::fake();
        $this->app->instance(HostResolver::class, new StaticHostResolver);

        // Page tests should not depend on compiled frontend assets.
        $this->withoutVite();

        // Keep test runs out of the real logs (log events are still dispatched).
        config([
            'logging.channels.discovery.channels' => ['null'],
            'logging.channels.audits.channels' => ['null'],
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/Fixtures/{$name}"), true, flags: JSON_THROW_ON_ERROR);
    }
}

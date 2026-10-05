<?php

namespace Tests\Feature\Auditing;

use App\Auditing\Contracts\HostResolver;
use App\Auditing\Exceptions\WebsiteFetchException;
use App\Auditing\WebsiteFetcher;
use App\Enums\AuditFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\StaticHostResolver;
use Tests\TestCase;

class WebsiteFetcherTest extends TestCase
{
    public function test_follows_redirects_to_the_https_homepage(): void
    {
        Http::fake([
            'http://alreef.sa/' => Http::response('', 301, ['Location' => 'https://alreef.sa/']),
            'https://alreef.sa/' => Http::response('', 302, ['Location' => '/ar/']),
            'https://alreef.sa/ar/' => Http::response('<html><title>Al Reef</title></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $result = $this->fetcher()->fetch('http://alreef.sa/');

        $this->assertSame('https://alreef.sa/ar/', $result->finalUrl);
        $this->assertSame(['https://alreef.sa/', 'https://alreef.sa/ar/'], $result->redirects);
        $this->assertTrue($result->isHttps());
        $this->assertTrue($result->sslValid);
        $this->assertTrue($result->isHtml());
        $this->assertSame(200, $result->status);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_starts_with($request->header('User-Agent')[0], 'Mozilla/5.0 (compatible; LeadsAudit/1.0'));
    }

    public function test_a_site_served_over_plain_http_is_not_https(): void
    {
        Http::fake(['http://plain.sa/' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertFalse($this->fetcher()->fetch('http://plain.sa/')->isHttps());
    }

    public function test_rejects_urls_that_are_not_web_addresses(): void
    {
        $this->assertFailure(AuditFailure::InvalidUrl, 'ftp://files.example.sa/');
        $this->assertFailure(AuditFailure::InvalidUrl, 'https://user:secret@example.sa/');
        $this->assertFailure(AuditFailure::InvalidUrl, 'not a url');
        Http::assertNothingSent();
    }

    public function test_refuses_addresses_on_private_or_reserved_networks(): void
    {
        $this->app->instance(HostResolver::class, new StaticHostResolver(['intranet.example.sa' => ['10.0.0.5']]));

        $this->assertFailure(AuditFailure::BlockedHost, 'https://intranet.example.sa/');
        $this->assertFailure(AuditFailure::BlockedHost, 'http://169.254.169.254/latest/meta-data/');
        $this->assertFailure(AuditFailure::BlockedHost, 'http://127.0.0.1:8080/');
        Http::assertNothingSent();
    }

    public function test_a_redirect_to_a_private_address_is_refused(): void
    {
        Http::fake(['https://shop.example.sa/' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        $this->assertFailure(AuditFailure::BlockedHost, 'https://shop.example.sa/');
        Http::assertSentCount(1);
    }

    public function test_a_domain_that_does_not_resolve_is_a_dns_failure(): void
    {
        $this->app->instance(HostResolver::class, new StaticHostResolver(default: null));

        $exception = $this->assertFailure(AuditFailure::DnsFailure, 'https://gone.example.sa/');

        $this->assertTrue($exception->isRetryable());
        Http::assertNothingSent();
    }

    public function test_an_invalid_certificate_is_tolerated_and_reported(): void
    {
        Http::fake(['https://oldsite.sa/' => Http::sequence()
            ->pushFailedConnection('cURL error 60: SSL certificate problem: certificate has expired')
            ->push('<html><title>Old site</title></html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->fetcher()->fetch('https://oldsite.sa/');

        $this->assertTrue($result->isHttps());
        $this->assertFalse($result->sslValid);
        Http::assertSentCount(2);
    }

    public function test_falls_back_to_http_when_https_cannot_be_reached(): void
    {
        Http::fake([
            'https://nossl.sa/' => Http::failedConnection('cURL error 7: Failed to connect to nossl.sa port 443'),
            'http://nossl.sa/' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->fetcher()->fetch('https://nossl.sa/');

        $this->assertFalse($result->isHttps());
        $this->assertTrue($result->usedHttpFallback);
        $this->assertSame('http://nossl.sa/', $result->finalUrl);
    }

    public function test_reports_the_https_failure_when_the_http_fallback_fails_too(): void
    {
        Http::fake([
            'https://down.sa/' => Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds'),
            'http://down.sa/' => Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds'),
        ]);

        $exception = $this->assertFailure(AuditFailure::Timeout, 'https://down.sa/');

        $this->assertTrue($exception->isRetryable());
        $this->assertStringContainsString('https://down.sa/', $exception->getMessage());
    }

    public function test_stops_following_endless_redirects(): void
    {
        Http::fake(['https://loop.sa/*' => Http::response('', 302, ['Location' => '/again'])]);

        $this->assertFailure(AuditFailure::TooManyRedirects, 'https://loop.sa/start');
        Http::assertSentCount(6);
    }

    public function test_keeps_at_most_the_configured_number_of_bytes(): void
    {
        config(['auditing.http.max_bytes' => 100]);
        Http::fake(['https://big.sa/' => Http::response('<html>'.str_repeat('x', 500).'</html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->fetcher()->fetch('https://big.sa/');

        $this->assertSame(100, strlen($result->body));
        $this->assertTrue($result->truncated);
    }

    private function assertFailure(AuditFailure $expected, string $url): WebsiteFetchException
    {
        try {
            $this->fetcher()->fetch($url);
        } catch (WebsiteFetchException $e) {
            $this->assertSame($expected, $e->failure);

            return $e;
        }

        $this->fail("Expected the fetch of {$url} to fail with {$expected->value}.");
    }

    private function fetcher(): WebsiteFetcher
    {
        return $this->app->make(WebsiteFetcher::class);
    }
}

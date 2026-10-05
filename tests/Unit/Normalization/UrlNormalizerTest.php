<?php

namespace Tests\Unit\Normalization;

use App\Support\Normalization\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function urls(): array
    {
        return [
            'missing scheme' => ['example.sa', 'https://example.sa'],
            'www without scheme' => ['www.Example.com/Menu', 'https://www.example.com/Menu'],
            'uppercase scheme and host' => ['HTTP://EXAMPLE.COM/About', 'http://example.com/About'],
            'trailing slash on root' => ['https://example.com/', 'https://example.com'],
            'default port removed' => ['https://example.com:443/path', 'https://example.com/path'],
            'custom port kept' => ['http://example.com:8080', 'http://example.com:8080'],
            'tracking parameters removed' => ['https://example.com/?utm_source=osm&id=5&fbclid=abc', 'https://example.com/?id=5'],
            'fragment removed' => ['https://example.com/page#section', 'https://example.com/page'],
            'credentials removed' => ['https://user:pass@example.com', 'https://example.com'],
            'protocol-relative' => ['//example.com', 'https://example.com'],
            'first of several' => ['https://a.example.com; https://b.example.com', 'https://a.example.com'],
            'arabic domain' => ['https://مثال.السعودية', 'https://مثال.السعودية'],
            'mailto is not a website' => ['mailto:info@example.com', null],
            'javascript is not a website' => ['javascript:alert(1)', null],
            'ftp is not a website' => ['ftp://example.com', null],
            'no tld' => ['localhost', null],
            'ip address' => ['http://192.168.1.1', null],
            'garbage' => ['not a url', null],
            'empty' => ['  ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('urls')]
    public function test_it_normalizes_urls(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, (new UrlNormalizer)->normalize($input));
    }
}

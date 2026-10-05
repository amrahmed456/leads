<?php

namespace App\Auditing;

use App\Auditing\Data\FetchResult;
use App\Support\Normalization\Text;
use App\Support\Normalization\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A parsed homepage exposing the parts the detectors inspect. Parsed once
 * per audit; nothing here is persisted.
 */
final class HtmlDocument
{
    private const MAX_TEXT_LENGTH = 300_000;

    private const MAX_ELEMENTS = 2_000;

    private const LOWER = 'translate(%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    private readonly DOMXPath $xpath;

    /** @var list<array{href: string, text: string}>|null */
    private ?array $links = null;

    private ?string $visibleText = null;

    private ?string $searchableText = null;

    /**
     * @param  array<string, string>  $headers  Lower-cased response headers
     * @param  list<string>  $cookies  Names of cookies set by the response
     */
    public function __construct(
        public readonly string $html,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly array $cookies = [],
    ) {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.(trim($html) !== '' ? $html : '<html></html>'), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    public static function fromFetch(FetchResult $result): self
    {
        $html = $result->body;
        $charset = $result->charset();

        // Many Arabic sites still use windows-1256; iconv supports it, mbstring does not.
        if ($charset !== null && ! in_array($charset, ['utf-8', 'utf8'], true)) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $html);

            if ($converted !== false) {
                $html = (string) preg_replace('/<meta[^>]+charset[^>]*>/i', '', $converted);
            }
        }

        return new self(mb_scrub($html, 'UTF-8'), $result->finalUrl, $result->headers, $result->cookies);
    }

    public function host(): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($this->url, PHP_URL_HOST)));
    }

    public function lang(): ?string
    {
        return $this->firstValue('//html/@lang');
    }

    public function dir(): ?string
    {
        return $this->firstValue('//html/@dir');
    }

    public function title(): ?string
    {
        return Text::clean($this->xpath->query('//title')->item(0)?->textContent, 255);
    }

    /**
     * Content of the first <meta> with the given name or property.
     */
    public function meta(string $name): ?string
    {
        return $this->metaAll($name)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function metaAll(string $name): array
    {
        $name = strtolower($name);

        return $this->values(sprintf(
            '//meta[%s="%s" or %s="%s"]/@content',
            sprintf(self::LOWER, '@name'), $name, sprintf(self::LOWER, '@property'), $name,
        ));
    }

    /**
     * Links with absolute URLs and their visible text, aria-label or title.
     *
     * @return list<array{href: string, text: string}>
     */
    public function links(): array
    {
        if ($this->links !== null) {
            return $this->links;
        }

        $links = [];

        foreach ($this->nodes('//a[@href] | //area[@href]') as $node) {
            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            $links[] = [
                'href' => UrlNormalizer::resolveReference($this->url, $href),
                'text' => $this->label($node),
            ];
        }

        return $this->links = $links;
    }

    /**
     * Visible labels of buttons and button-like elements.
     *
     * @return list<string>
     */
    public function buttons(): array
    {
        $labels = [];

        foreach ($this->nodes('//button | //input[@type="submit" or @type="button"] | //*[@role="button"]') as $node) {
            $label = $this->label($node) ?: (string) Text::clean($node->getAttribute('value'), 200);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<array{action: string, markers: string, fields: list<array{type: string, name: string}>}>
     */
    public function forms(): array
    {
        $forms = [];

        foreach ($this->nodes('//form') as $form) {
            $fields = [];

            foreach ($this->nodes('.//input | .//textarea | .//select', $form) as $field) {
                $fields[] = [
                    'type' => $field->tagName === 'input' ? strtolower($field->getAttribute('type') ?: 'text') : $field->tagName,
                    'name' => strtolower(implode(' ', array_filter([
                        $field->getAttribute('name'), $field->getAttribute('id'), $field->getAttribute('placeholder'),
                        $field->getAttribute('autocomplete'), $field->getAttribute('aria-label'),
                    ]))),
                ];
            }

            $forms[] = [
                'action' => UrlNormalizer::resolveReference($this->url, trim($form->getAttribute('action'))),
                'markers' => strtolower(implode(' ', [
                    $form->getAttribute('class'), $form->getAttribute('id'), $form->getAttribute('role'),
                    $form->getAttribute('name'), $form->getAttribute('action'),
                ])),
                'fields' => $fields,
            ];
        }

        return $forms;
    }

    /**
     * Absolute URLs of an element attribute, e.g. ('iframe', 'src').
     *
     * @return list<string>
     */
    public function urls(string $tag, string $attribute): array
    {
        return array_map(
            fn (string $value): string => UrlNormalizer::resolveReference($this->url, $value),
            $this->values("//{$tag}/@{$attribute}"),
        );
    }

    /**
     * @return list<string>
     */
    public function stylesheets(): array
    {
        return array_map(
            fn (string $value): string => UrlNormalizer::resolveReference($this->url, $value),
            $this->values(sprintf('//link[contains(%s, "stylesheet")]/@href', sprintf(self::LOWER, '@rel'))),
        );
    }

    public function inlineStyles(): string
    {
        return mb_substr(implode("\n", $this->values('//style')), 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * Languages of alternate versions declared with hreflang.
     *
     * @return list<string>
     */
    public function hreflangs(): array
    {
        return array_map('strtolower', $this->values('//link[@hreflang]/@hreflang'));
    }

    /**
     * Schema.org types declared in JSON-LD blocks (e.g. Product, Restaurant).
     *
     * @return list<string>
     */
    public function jsonLdTypes(): array
    {
        $types = [];

        foreach ($this->values('//script[@type="application/ld+json"]') as $json) {
            $data = json_decode($json, true);

            if (! is_array($data)) {
                continue;
            }

            array_walk_recursive($data, function (mixed $value, int|string $key) use (&$types): void {
                if ($key === '@type' && is_string($value)) {
                    $types[] = $value;
                }
            });
        }

        return array_values(array_unique($types));
    }

    public function exists(string $query): bool
    {
        return $this->xpath->query($query)->length > 0;
    }

    /**
     * Text a visitor can read: no scripts, styles or templates.
     */
    public function visibleText(): string
    {
        if ($this->visibleText !== null) {
            return $this->visibleText;
        }

        $excluded = 'not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template)';
        $query = $this->exists('//body') ? "//body//text()[{$excluded}]" : "//text()[{$excluded}]";
        $text = implode(' ', $this->values($query, self::MAX_ELEMENTS * 10));

        return $this->visibleText = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * Title, description and visible text normalized for keyword search.
     */
    public function searchableText(): string
    {
        return $this->searchableText ??= Text::searchable(implode(' ', [
            $this->title(), $this->meta('description'), $this->visibleText(),
        ]));
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    private function firstValue(string $query): ?string
    {
        return $this->values($query)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function values(string $query, int $limit = self::MAX_ELEMENTS): array
    {
        $values = [];

        foreach ($this->xpath->query($query) ?: [] as $index => $node) {
            if ($index >= $limit) {
                break;
            }

            $value = trim($node->nodeValue ?? '');

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(string $query, ?DOMNode $context = null): array
    {
        $nodes = [];

        foreach ($this->xpath->query($query, $context) ?: [] as $index => $node) {
            if ($index >= self::MAX_ELEMENTS) {
                break;
            }

            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function label(DOMElement $node): string
    {
        return (string) Text::clean(implode(' ', array_filter([
            $node->textContent, $node->getAttribute('aria-label'), $node->getAttribute('title'),
        ])), 200);
    }
}

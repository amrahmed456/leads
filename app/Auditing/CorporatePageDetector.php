<?php

namespace App\Auditing;

use App\Support\Normalization\Text;

/**
 * Finds links to pages that small independent businesses rarely have:
 * branch / store locators, franchise offers, careers and investor
 * relations. Each is a hint of a chain or corporate organization.
 */
final class CorporatePageDetector
{
    /** @var array<string, array{paths: list<string>, words: list<string>}> */
    private const PAGES = [
        'locations' => [
            'paths' => ['/locations', '/store-locator', '/stores', '/branches', '/our-branches', '/find-us', '/restaurants'],
            'words' => ['store locator', 'our branches', 'our locations', 'find a store', 'find a restaurant', 'فروعنا', 'الفروع', 'مواقعنا'],
        ],
        'franchise' => [
            'paths' => ['/franchise', '/franchising'],
            'words' => ['franchise', 'franchising', 'امتياز تجاري', 'الامتياز التجاري', 'فرنشايز', 'فرانشايز'],
        ],
        'careers' => [
            'paths' => ['/careers', '/jobs', '/join-us', '/work-with-us'],
            'words' => ['careers', 'join our team', 'وظائف', 'الوظائف', 'انضم لفريقنا'],
        ],
        'investors' => [
            'paths' => ['/investors', '/investor-relations', '/ir'],
            'words' => ['investor relations', 'investors', 'علاقات المستثمرين', 'المستثمرين'],
        ],
    ];

    /**
     * @return array<string, list<string>> Page kind => up to 3 linked URLs
     */
    public function detect(HtmlDocument $document): array
    {
        $found = [];
        $host = $document->host();

        foreach ($document->links() as $link) {
            $linkHost = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($link['href'], PHP_URL_HOST)));

            if ($linkHost !== '' && $linkHost !== $host && ! str_ends_with($linkHost, '.'.$host)) {
                continue;
            }

            $path = strtolower(rtrim((string) parse_url($link['href'], PHP_URL_PATH), '/'));
            $text = Text::searchable($link['text']);

            foreach (self::PAGES as $kind => $patterns) {
                if (count($found[$kind] ?? []) >= 3) {
                    continue;
                }

                $byPath = collect($patterns['paths'])->contains(fn (string $candidate): bool => $path === $candidate || str_ends_with($path, $candidate));
                $byWord = $text !== '' && collect($patterns['words'])->contains(fn (string $word): bool => preg_match('/(?:^|\s)'.preg_quote(Text::searchable($word), '/').'(?:$|\s)/u', $text) === 1);

                if ($byPath || $byWord) {
                    $found[$kind][] = mb_substr($link['href'], 0, 300);
                }
            }
        }

        return array_map(fn (array $urls): array => array_values(array_unique($urls)), $found);
    }
}

<?php

namespace App\Discovery\Providers\OpenStreetMap;

use App\Discovery\Data\DiscoveryQuery;

/**
 * Builds Overpass QL for a discovery query.
 *
 * The query selects nodes, ways and relations within the search radius that
 * carry the category's tags. When keywords are given, it also selects any
 * business-like element whose name contains one of them.
 *
 * @see https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL
 */
final class OverpassQueryBuilder
{
    /** Name tags matched against keywords. */
    private const NAME_KEYS = ['name', 'name:en', 'name:ar', 'brand', 'brand:en', 'brand:ar'];

    public function __construct(private readonly OsmTagMap $tagMap) {}

    public function build(DiscoveryQuery $query, int $timeoutSeconds, int $limit): string
    {
        $area = sprintf(
            '(around:%d,%s,%s)',
            $query->radiusMeters,
            $this->coordinate($query->latitude),
            $this->coordinate($query->longitude),
        );

        $statements = [];

        foreach ($this->tagMap->filtersFor($query->category) as $key => $values) {
            $statements[] = sprintf('nwr[%s~%s]%s;', $this->quote($key), $this->quote($this->anyOf($values)), $area);
        }

        if ($query->keywords !== []) {
            $statements[] = sprintf(
                'nwr[~%s~"."][~%s~%s,i]%s;',
                $this->quote($this->anyOf(OsmTagMap::BUSINESS_KEYS)),
                $this->quote($this->anyOf(self::NAME_KEYS)),
                $this->quote(implode('|', array_map($this->escapeRegex(...), $query->keywords))),
                $area,
            );
        }

        return implode("\n", [
            sprintf('[out:json][timeout:%d];', $timeoutSeconds),
            '(',
            ...array_map(fn (string $statement): string => '  '.$statement, $statements),
            ');',
            sprintf('out tags center qt %d;', $limit),
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    private function anyOf(array $values): string
    {
        return '^('.implode('|', array_map($this->escapeRegex(...), $values)).')$';
    }

    private function escapeRegex(string $value): string
    {
        return (string) preg_replace('/[.\\\\+*?\[\]^$(){}|]/', '\\\\$0', $value);
    }

    private function quote(string $value): string
    {
        $value = (string) preg_replace('/[\p{Cc}]+/u', ' ', $value);

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function coordinate(float $value): string
    {
        return number_format($value, 7, '.', '');
    }
}

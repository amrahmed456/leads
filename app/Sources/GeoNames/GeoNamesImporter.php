<?php

namespace App\Sources\GeoNames;

use App\Models\GeoPlace;
use App\Support\Streaming\MalformedFileException;

/**
 * Imports a GeoNames cities file (e.g. cities15000.txt, tab-separated) for
 * the configured countries, streaming it line by line.
 *
 * @see https://download.geonames.org/export/dump/readme.txt
 */
final class GeoNamesImporter
{
    private const BATCH_SIZE = 500;

    /**
     * @param  list<string>  $countries  Upper-case ISO codes to keep
     */
    public function __construct(private readonly array $countries) {}

    /**
     * @return int Cities imported
     */
    public function import(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new MalformedFileException("Cannot open {$path}.");
        }

        $rows = [];
        $imported = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $columns = explode("\t", rtrim($line, "\r\n"));

                if (count($columns) < 15 || ! in_array($columns[8], $this->countries, true) || ! is_numeric($columns[4]) || ! is_numeric($columns[5])) {
                    continue;
                }

                $names = [$columns[1], $columns[2], ...array_filter(explode(',', $columns[3]), fn (string $name): bool => preg_match('/^[\p{Latin}\p{Arabic}\s\'\-.]+$/u', $name) === 1)];
                $now = now();

                $rows[] = [
                    'geonames_id' => (int) $columns[0],
                    'name' => mb_substr($columns[1], 0, 200),
                    'ascii_name' => mb_substr($columns[2], 0, 200),
                    'name_ar' => CityResolver::arabicName($names),
                    'search_names' => CityResolver::searchNames($names),
                    'country_code' => $columns[8],
                    'admin1' => $columns[10] !== '' ? mb_substr($columns[10], 0, 20) : null,
                    'feature_code' => mb_substr($columns[7], 0, 10),
                    'latitude' => (float) $columns[4],
                    'longitude' => (float) $columns[5],
                    'population' => (int) $columns[14],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= self::BATCH_SIZE) {
                    $imported += $this->flush($rows);
                    $rows = [];
                }
            }

            return $imported + $this->flush($rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        if ($rows !== []) {
            GeoPlace::query()->upsert($rows, ['geonames_id'], ['name', 'ascii_name', 'name_ar', 'search_names', 'country_code', 'admin1', 'feature_code', 'latitude', 'longitude', 'population', 'updated_at']);
        }

        return count($rows);
    }
}

<?php

namespace App\Sources\OpenAddresses;

use App\Models\AddressPoint;
use App\Support\Normalization\Text;
use App\Support\Streaming\CsvRecordReader;

/**
 * Imports an OpenAddresses CSV (LON, LAT, NUMBER, STREET, UNIT, CITY,
 * DISTRICT, REGION, POSTCODE, ID, HASH), streaming it in batches.
 *
 * @see https://openaddresses.io/
 */
final class OpenAddressesImporter
{
    private const BATCH_SIZE = 1000;

    /**
     * @return array{imported: int, invalid: int}
     */
    public function import(string $path, string $dataset, ?string $countryCode = null): array
    {
        $rows = [];
        $summary = ['imported' => 0, 'invalid' => 0];

        foreach ((new CsvRecordReader)->read($path) as [$record]) {
            $latitude = $record['lat'] ?? null;
            $longitude = $record['lon'] ?? null;

            if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
                $summary['invalid']++;

                continue;
            }

            $now = now();
            $rows[] = [
                'source_id' => mb_substr((string) (($record['hash'] ?? '') ?: ($record['id'] ?? '') ?: sprintf('%.6F,%.6F', $latitude, $longitude)), 0, 100),
                'dataset' => mb_substr($dataset, 0, 100),
                'number' => Text::clean($record['number'] ?? null, 50),
                'street' => Text::clean($record['street'] ?? null, 200),
                'unit' => Text::clean($record['unit'] ?? null, 50),
                'district' => Text::clean($record['district'] ?? null, 120),
                'city' => Text::clean($record['city'] ?? null, 120),
                'region' => Text::clean($record['region'] ?? null, 120),
                'postcode' => Text::clean($record['postcode'] ?? null, 20),
                'country_code' => $countryCode,
                'latitude' => round((float) $latitude, 7),
                'longitude' => round((float) $longitude, 7),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::BATCH_SIZE) {
                $summary['imported'] += $this->flush($rows);
                $rows = [];
            }
        }

        $summary['imported'] += $this->flush($rows);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        if ($rows !== []) {
            AddressPoint::query()->upsert($rows, ['dataset', 'source_id'], ['number', 'street', 'unit', 'district', 'city', 'region', 'postcode', 'country_code', 'latitude', 'longitude', 'updated_at']);
        }

        return count($rows);
    }
}

<?php

namespace App\Support\Streaming;

use InvalidArgumentException;

final class Readers
{
    public static function forFormat(string $format): RecordReader
    {
        return match ($format) {
            'geojson' => new GeoJsonFeatureReader,
            'ndjson' => new NdjsonReader,
            'csv' => new CsvRecordReader,
            default => throw new InvalidArgumentException("Unsupported file format [{$format}]."),
        };
    }
}

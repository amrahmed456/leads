<?php

namespace App\Support\Streaming;

use Generator;

/**
 * Streams newline-delimited JSON (NDJSON, GeoJSONSeq / GeoJSONL): one JSON
 * value per line, optionally prefixed with the RS character.
 */
final class NdjsonReader implements RecordReader
{
    /** Longest accepted line. */
    private const MAX_LINE_BYTES = 5_000_000;

    public function read(string $path, int $offset = 0): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new MalformedFileException("Cannot open {$path}.");
        }

        try {
            fseek($handle, $offset);

            while (($line = fgets($handle, self::MAX_LINE_BYTES)) !== false) {
                $nextOffset = (int) ftell($handle);
                $line = trim($line, " \t\r\n\x1e");

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                yield [is_array($decoded) ? $decoded : null, $nextOffset];
            }
        } finally {
            fclose($handle);
        }
    }
}

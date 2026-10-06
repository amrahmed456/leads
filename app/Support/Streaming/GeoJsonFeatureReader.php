<?php

namespace App\Support\Streaming;

use Generator;

/**
 * Streams the features of a GeoJSON FeatureCollection (or a JSON array of
 * features) without decoding the whole document: it scans for the
 * "features" array and decodes one feature object at a time.
 */
final class GeoJsonFeatureReader implements RecordReader
{
    private const CHUNK_BYTES = 65_536;

    /** A single feature larger than this is treated as a malformed file. */
    private const MAX_FEATURE_BYTES = 5_000_000;

    /** How far into the file the "features" array must start. */
    private const MAX_HEADER_BYTES = 1_000_000;

    public function read(string $path, int $offset = 0): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new MalformedFileException("Cannot open {$path}.");
        }

        try {
            $position = $offset > 0 ? $offset : $this->featuresStart($handle);
            fseek($handle, $position);

            $buffer = '';
            $bufferStart = $position;

            while (true) {
                // Skip separators between features.
                $index = 0;
                $length = strlen($buffer);

                while ($index < $length && strpbrk($buffer[$index], " \t\r\n,") !== false) {
                    $index++;
                }

                if ($index === $length) {
                    $chunk = fread($handle, self::CHUNK_BYTES);

                    if ($chunk === false || $chunk === '') {
                        return;
                    }

                    $bufferStart += $length;
                    $buffer = $chunk;

                    continue;
                }

                if ($buffer[$index] === ']') {
                    return;
                }

                if ($buffer[$index] !== '{') {
                    throw new MalformedFileException('Unexpected character in the features array at byte '.($bufferStart + $index).'.');
                }

                $buffer = substr($buffer, $index);
                $bufferStart += $index;
                $end = $this->objectEnd($buffer, $handle);

                $json = substr($buffer, 0, $end);
                $decoded = json_decode($json, true);
                $nextOffset = $bufferStart + $end;

                yield [is_array($decoded) ? $decoded : null, $nextOffset];

                $buffer = substr($buffer, $end);
                $bufferStart = $nextOffset;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Byte offset just inside the features array.
     *
     * @param  resource  $handle
     */
    private function featuresStart($handle): int
    {
        $head = '';

        while (strlen($head) < self::MAX_HEADER_BYTES && ! feof($handle)) {
            $head .= (string) fread($handle, self::CHUNK_BYTES);

            if (preg_match('/"features"\s*:\s*\[/', $head, $matches, PREG_OFFSET_CAPTURE)) {
                return $matches[0][1] + strlen($matches[0][0]);
            }

            // A plain JSON array of features.
            if (preg_match('/^\s*\[/', $head, $matches)) {
                return strlen($matches[0]);
            }
        }

        throw new MalformedFileException('No "features" array found; is this a GeoJSON FeatureCollection?');
    }

    /**
     * Length of the JSON object at the start of the buffer, reading more of
     * the file into the buffer as needed.
     *
     * @param  resource  $handle
     */
    private function objectEnd(string &$buffer, $handle): int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $index = 0;

        while (true) {
            $length = strlen($buffer);

            for (; $index < $length; $index++) {
                $char = $buffer[$index];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{' || $char === '[') {
                    $depth++;
                } elseif ($char === '}' || $char === ']') {
                    $depth--;

                    if ($depth === 0) {
                        return $index + 1;
                    }
                }
            }

            if ($length > self::MAX_FEATURE_BYTES) {
                throw new MalformedFileException('A feature is larger than '.self::MAX_FEATURE_BYTES.' bytes.');
            }

            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                throw new MalformedFileException('The file ends in the middle of a feature.');
            }

            $buffer .= $chunk;
        }
    }
}

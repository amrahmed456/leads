<?php

namespace App\Support\Streaming;

use Generator;

/**
 * Streams a CSV file with a header row as associative records.
 */
final class CsvRecordReader implements RecordReader
{
    public function read(string $path, int $offset = 0): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new MalformedFileException("Cannot open {$path}.");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! is_array($header) || $header === [null]) {
                throw new MalformedFileException('The CSV file has no header row.');
            }

            $header = array_map(fn (mixed $column): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $column))), $header);

            if ($offset > ftell($handle)) {
                fseek($handle, $offset);
            }

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $nextOffset = (int) ftell($handle);

                if ($row === [null]) {
                    continue;
                }

                yield [count($row) === count($header) ? array_combine($header, $row) : null, $nextOffset];
            }
        } finally {
            fclose($handle);
        }
    }
}

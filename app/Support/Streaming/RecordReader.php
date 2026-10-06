<?php

namespace App\Support\Streaming;

use Generator;

/**
 * Reads records from a large file one at a time, never loading the file
 * into memory, and reports the byte offset after each record so a later
 * run can resume exactly there.
 */
interface RecordReader
{
    /**
     * @param  int  $offset  Byte offset returned by a previous read (0 to start)
     * @return Generator<int, array{0: array<mixed>|null, 1: int}> [record or null when malformed, offset after it]
     *
     * @throws MalformedFileException when the file cannot be read as this format at all
     */
    public function read(string $path, int $offset = 0): Generator;
}

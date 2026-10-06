<?php

namespace App\Sources\Overture;

use App\Models\OvertureImport;
use App\Models\SourcePlace;
use App\Support\Streaming\Readers;
use Illuminate\Support\Facades\Storage;

/**
 * Imports a bounded Overture extract into source_places, one chunk at a
 * time. Each call processes at most $maxRows records or $maxSeconds, saves
 * the byte offset and returns; the next call resumes there. Nothing is
 * ever loaded into memory beyond one batch.
 */
final class OvertureExtractImporter
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly OvertureRecordMapper $mapper,
        private readonly string $disk,
        private readonly float $minConfidence,
    ) {}

    /**
     * @return bool True when the whole file has been imported
     */
    public function process(OvertureImport $import, int $maxRows, int $maxSeconds): bool
    {
        $path = Storage::disk($this->disk)->path((string) $import->file_path);
        $started = microtime(true);
        $rows = [];
        $processed = 0;
        $offset = $import->byte_offset;
        $counts = ['imported' => 0, 'skipped' => 0, 'invalid' => 0];

        foreach (Readers::forFormat($import->format)->read($path, $import->byte_offset) as [$record, $nextOffset]) {
            $processed++;
            $offset = $nextOffset;
            $row = $record === null ? null : $this->mapper->map($record);

            if ($row === null) {
                $counts['invalid']++;
            } elseif (! $this->accepts($row, $import)) {
                $counts['skipped']++;
            } else {
                $rows[] = $row;
            }

            if (count($rows) >= self::BATCH_SIZE) {
                $counts['imported'] += SourcePlace::upsertRows($rows, SourcePlace::DATASET_OVERTURE_IMPORT, $import->getKey());
                $rows = [];
            }

            if ($processed >= $maxRows || microtime(true) - $started >= $maxSeconds) {
                $this->save($import, $rows, $counts, $processed, $offset);

                return false;
            }
        }

        $this->save($import, $rows, $counts, $processed, $offset);

        return true;
    }

    /**
     * Junk (below the minimum existence confidence) and places outside the
     * extract's declared area are not kept.
     *
     * @param  array<string, mixed>  $row
     */
    private function accepts(array $row, OvertureImport $import): bool
    {
        if ($row['confidence'] !== null && $row['confidence'] < $this->minConfidence) {
            return false;
        }

        $bbox = $import->bbox;

        return $bbox === null || (
            $row['latitude'] >= $bbox['min_lat'] && $row['latitude'] <= $bbox['max_lat']
            && $row['longitude'] >= $bbox['min_lon'] && $row['longitude'] <= $bbox['max_lon']
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{imported: int, skipped: int, invalid: int}  $counts
     */
    private function save(OvertureImport $import, array $rows, array $counts, int $processed, int $offset): void
    {
        $counts['imported'] += SourcePlace::upsertRows($rows, SourcePlace::DATASET_OVERTURE_IMPORT, $import->getKey());

        $import->forceFill([
            'rows_processed' => $import->rows_processed + $processed,
            'rows_imported' => $import->rows_imported + $counts['imported'],
            'rows_skipped' => $import->rows_skipped + $counts['skipped'],
            'rows_invalid' => $import->rows_invalid + $counts['invalid'],
            'byte_offset' => $offset,
        ])->save();
    }

    /**
     * Remove an import and every place it brought in.
     */
    public function remove(OvertureImport $import): void
    {
        SourcePlace::query()
            ->where('dataset_type', SourcePlace::DATASET_OVERTURE_IMPORT)
            ->where('dataset_id', $import->getKey())
            ->delete();

        if ($import->file_path !== null) {
            Storage::disk($this->disk)->delete($import->file_path);
        }

        $import->delete();
    }
}

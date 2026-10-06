<?php

namespace App\Jobs;

use App\Auditing\Exceptions\WebsiteFetchException;
use App\Auditing\UrlGuard;
use App\Models\OvertureImport;
use App\Sources\Overture\OvertureDuckDb;
use App\Sources\Overture\OvertureExtractImporter;
use App\Sources\SecretScrubber;
use App\Sources\SourceRegistry;
use App\Support\BoundedDownloader;
use App\Support\DownloadException;
use App\Support\Streaming\MalformedFileException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Imports one chunk of an Overture extract, then queues itself for the
 * next chunk, so each run fits comfortably in a cron-driven worker's time
 * limit and an interrupted import resumes where it stopped.
 */
class ImportOvertureExtract implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 280;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public OvertureImport $import)
    {
        $this->onQueue(config('sources.queue'));
    }

    public function handle(OvertureExtractImporter $importer, BoundedDownloader $downloader, OvertureDuckDb $duckDb, SourceRegistry $sources): void
    {
        if (! $this->import->status->isActive()) {
            return;
        }

        try {
            $this->ensureFile($downloader, $duckDb);
            $this->import->markRunning();

            do {
                $done = $importer->process(
                    $this->import,
                    (int) config('sources.overture.chunk_rows'),
                    (int) config('sources.overture.chunk_seconds'),
                );
            } while (! $done && $this->runsSynchronously() && $this->import->refresh()->status->isActive());
        } catch (DownloadException $e) {
            if ($e->retryable && $this->attempts() < $this->tries && ! $this->runsSynchronously()) {
                throw $e;
            }

            $this->fail($sources, $e->getMessage());

            return;
        } catch (MalformedFileException|RuntimeException $e) {
            $this->fail($sources, $e->getMessage());

            return;
        }

        if (! $done) {
            // Paused by the admin, or more chunks to go.
            if ($this->import->refresh()->status->isActive()) {
                self::dispatch($this->import);
            }

            return;
        }

        $this->import->markCompleted();
        $sources->recordSuccess('overture');

        Log::channel('discovery')->info('Overture extract imported.', [
            'import_id' => $this->import->getKey(),
            'rows' => $this->import->rows_processed,
            'imported' => $this->import->rows_imported,
            'skipped' => $this->import->rows_skipped,
            'invalid' => $this->import->rows_invalid,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->import->status->isActive()) {
            $this->import->markFailed($exception?->getMessage() ?: 'The import stopped unexpectedly.');
        }
    }

    /**
     * Fetch the extract file for remote and DuckDB imports.
     */
    private function ensureFile(BoundedDownloader $downloader, OvertureDuckDb $duckDb): void
    {
        if ($this->import->file_path !== null) {
            return;
        }

        $disk = Storage::disk(config('sources.overture.disk'));
        $extension = $this->import->format === 'geojson' ? 'geojson' : ($this->import->format === 'csv' ? 'csv' : 'ndjson');
        $relative = trim((string) config('sources.overture.directory'), '/')."/import-{$this->import->getKey()}.{$extension}";
        $absolute = $disk->path($relative);

        if ($this->import->mode === 'remote') {
            // An extract URL is fetched by the server: never let it reach a private network.
            try {
                app(UrlGuard::class)->resolve((string) $this->import->remote_url);
            } catch (WebsiteFetchException $e) {
                throw new RuntimeException('The extract URL is not allowed: '.$e->getMessage());
            }

            $size = $downloader->download(
                (string) $this->import->remote_url,
                $absolute,
                (int) config('sources.overture.max_remote_mb') * 1_048_576,
                timeoutSeconds: 240,
            );
        } elseif ($this->import->mode === 'duckdb') {
            if (! $duckDb->isAvailable()) {
                throw new RuntimeException((string) $duckDb->unavailableReason());
            }

            $disk->makeDirectory(dirname($relative));
            $duckDb->extract((array) $this->import->bbox, $absolute);
            $size = (int) @filesize($absolute);
        } else {
            throw new RuntimeException('The extract file is missing.');
        }

        $this->import->forceFill(['file_path' => $relative, 'file_size' => $size])->save();
    }

    private function fail(SourceRegistry $sources, string $message): void
    {
        $message = SecretScrubber::scrub($message);
        $this->import->markFailed($message);
        $sources->recordFailure('overture', "Import #{$this->import->getKey()}: {$message}");

        Log::channel('discovery')->warning('Overture extract import failed.', [
            'import_id' => $this->import->getKey(),
            'message' => $message,
        ]);
    }

    private function runsSynchronously(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }
}

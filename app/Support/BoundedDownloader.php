<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads a file straight to disk with a hard size limit, so a large
 * dataset can never fill PHP memory or the hosting account by accident.
 */
final class BoundedDownloader
{
    public function __construct(private readonly string $userAgent) {}

    /**
     * @return int Bytes written
     *
     * @throws DownloadException
     */
    public function download(string $url, string $destination, int $maxBytes, int $timeoutSeconds = 120): int
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new DownloadException("Cannot create {$directory}.", retryable: false);
        }

        try {
            $response = Http::withUserAgent($this->userAgent)
                ->connectTimeout(15)
                ->timeout($timeoutSeconds)
                ->withOptions([
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                        $length = (int) $response->getHeaderLine('Content-Length');

                        if ($length > $maxBytes) {
                            throw new RuntimeException('The file is '.self::megabytes($length).', above the '.self::megabytes($maxBytes).' limit.');
                        }
                    },
                    'progress' => function (int $expected, int $downloaded) use ($maxBytes): void {
                        if ($downloaded > $maxBytes) {
                            throw new RuntimeException('The download exceeded the '.self::megabytes($maxBytes).' limit.');
                        }
                    },
                ])
                ->sink($destination)
                ->get($url);
        } catch (ConnectionException $e) {
            @unlink($destination);

            throw new DownloadException('Download failed: '.$e->getMessage(), retryable: true, previous: $e);
        } catch (Throwable $e) {
            @unlink($destination);

            throw new DownloadException('Download refused: '.$e->getMessage(), retryable: false, previous: $e);
        }

        if ($response->failed()) {
            @unlink($destination);

            throw new DownloadException(
                "The server returned HTTP {$response->status()}.",
                retryable: $response->status() === 429 || $response->serverError(),
                status: $response->status(),
            );
        }

        clearstatcache(true, $destination);
        $size = is_file($destination) ? (int) filesize($destination) : 0;

        if ($size > $maxBytes) {
            @unlink($destination);

            throw new DownloadException('The file is '.self::megabytes($size).', above the '.self::megabytes($maxBytes).' limit.', retryable: false);
        }

        return $size;
    }

    public static function megabytes(int $bytes): string
    {
        return round($bytes / 1_048_576, 1).' MB';
    }
}

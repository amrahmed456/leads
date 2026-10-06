<?php

namespace App\Sources\AllThePlaces;

use App\Support\DownloadException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Finds the latest successful AllThePlaces run and the GeoJSON output of a
 * single spider in it. The global output archive is never downloaded.
 *
 * @see https://www.alltheplaces.xyz/
 */
final class AllThePlacesClient
{
    public function __construct(
        private readonly string $latestRunUrl,
        private readonly string $userAgent,
    ) {}

    /**
     * @return array{run_id: string, output_url: string}
     *
     * @throws DownloadException
     */
    public function latestRun(): array
    {
        $cached = Cache::get('alltheplaces:latest-run');

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::withUserAgent($this->userAgent)->acceptJson()->connectTimeout(10)->timeout(30)->get($this->latestRunUrl);
        } catch (ConnectionException $e) {
            throw new DownloadException('Could not reach AllThePlaces: '.$e->getMessage(), retryable: true, previous: $e);
        }

        $runId = $response->json('run_id');
        $outputUrl = $response->json('output_url');

        if ($response->failed() || ! is_string($runId) || ! is_string($outputUrl) || ! str_starts_with($outputUrl, 'https://')) {
            throw new DownloadException('AllThePlaces returned no usable latest run (HTTP '.$response->status().').', retryable: $response->serverError());
        }

        $run = ['run_id' => $runId, 'output_url' => $outputUrl];
        Cache::put('alltheplaces:latest-run', $run, now()->addHours(6));

        return $run;
    }

    /**
     * URL of one spider's GeoJSON in a run: <run>/output/<spider>.geojson
     *
     * @param  array{run_id: string, output_url: string}  $run
     */
    public function spiderUrl(array $run, string $spider): string
    {
        if (! self::isValidSpider($spider)) {
            throw new InvalidArgumentException("Invalid spider name [{$spider}].");
        }

        // output_url points to the run's archive (…/runs/<id>/output.zip); spider files sit next to it.
        $base = (string) preg_replace('#/output(\.zip|\.tar\.gz)?$#', '', rtrim($run['output_url'], '/'));

        return "{$base}/output/{$spider}.geojson";
    }

    public static function isValidSpider(string $spider): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{2,100}$/', $spider);
    }
}

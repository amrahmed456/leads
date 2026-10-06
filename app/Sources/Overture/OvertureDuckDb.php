<?php

namespace App\Sources\Overture;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Optional advanced mode: extract a small bounding box of Overture Places
 * straight from the public release with a local DuckDB binary.
 *
 * Production never depends on it. It is used only when enabled in the
 * configuration, the binary exists and PHP may run processes; otherwise
 * extracts are uploaded or downloaded as files.
 */
final class OvertureDuckDb
{
    private const RELEASE_URL = 's3://overturemaps-us-west-2/release/%s/theme=places/type=place/*';

    /**
     * @param  array{enabled: bool, binary: string, release: string, max_rows: int, timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if (! $this->config['enabled']) {
            return 'DuckDB mode is disabled (OVERTURE_DUCKDB_ENABLED=false).';
        }

        if (! function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return 'PHP cannot run external programs on this server (proc_open is disabled).';
        }

        try {
            $process = new Process([$this->config['binary'], '--version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() ? null : 'The DuckDB binary did not run: '.trim($process->getErrorOutput());
        } catch (\Throwable $e) {
            return 'The DuckDB binary was not found: '.$e->getMessage();
        }
    }

    /**
     * Write the places inside the box to an NDJSON file.
     *
     * @param  array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}  $bbox
     */
    public function extract(array $bbox, string $destination): void
    {
        $process = new Process([$this->config['binary'], '-c', $this->query($bbox, $destination)]);
        $process->setTimeout($this->config['timeout']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('DuckDB extraction failed: '.mb_substr(trim($process->getErrorOutput()), 0, 500));
        }
    }

    /**
     * @param  array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}  $bbox
     */
    public function query(array $bbox, string $destination): string
    {
        $coordinate = fn (mixed $value): string => number_format((float) $value, 6, '.', '');

        return implode(' ', [
            'INSTALL spatial; LOAD spatial; INSTALL httpfs; LOAD httpfs; SET s3_region=\'us-west-2\';',
            'COPY (SELECT id, names, categories, taxonomy, basic_category, confidence, operating_status, websites, socials,',
            'emails, phones, brand, addresses, sources, ST_X(geometry) AS lon, ST_Y(geometry) AS lat',
            "FROM read_parquet('".sprintf(self::RELEASE_URL, preg_replace('/[^0-9.\-]/', '', $this->config['release']))."', hive_partitioning=1)",
            'WHERE bbox.xmin BETWEEN '.$coordinate($bbox['min_lon']).' AND '.$coordinate($bbox['max_lon']),
            'AND bbox.ymin BETWEEN '.$coordinate($bbox['min_lat']).' AND '.$coordinate($bbox['max_lat']),
            'LIMIT '.(int) $this->config['max_rows'].')',
            "TO '".str_replace("'", "''", $destination)."' (FORMAT JSON);",
        ]);
    }
}

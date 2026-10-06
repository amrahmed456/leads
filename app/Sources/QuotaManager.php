<?php

namespace App\Sources;

use App\Models\SourceUsage;
use Illuminate\Support\Facades\DB;

/**
 * Tracks daily usage per source and enforces daily budgets.
 *
 * Units are what the provider bills: credits for Geoapify, downloads for
 * AllThePlaces, requests elsewhere. consume() is atomic, so concurrent
 * workers can never overrun the budget together.
 */
final class QuotaManager
{
    public function __construct(private readonly SourceRegistry $sources) {}

    /**
     * The daily budget: the admin's setting, else the catalog default. Null means unlimited.
     */
    public function dailyLimit(string $key): ?int
    {
        $setting = $this->sources->setting($key, 'daily_quota');

        if (is_numeric($setting)) {
            return max(0, (int) $setting);
        }

        return $this->sources->has($key) ? $this->sources->descriptor($key)->dailyQuota() : null;
    }

    /**
     * Reserve units before calling the provider.
     *
     * @throws QuotaExceededException when the reservation would exceed the daily budget
     */
    public function consume(string $key, int $units = 1): void
    {
        $units = max(0, $units);
        $limit = $this->dailyLimit($key);
        $this->ensureRow($key);

        $query = DB::table('source_usage')->where('source', $key)->where('date', $this->today());

        if ($limit !== null) {
            $query->where('exhausted', false)->whereRaw('units + ? <= ?', [$units, $limit]);
        }

        $updated = $query->update([
            'units' => DB::raw('units + '.$units),
            'requests' => DB::raw('requests + 1'),
            'updated_at' => now(),
        ]);

        if ($updated === 0 && $limit !== null) {
            throw new QuotaExceededException($key, $this->used($key), $limit, $this->unit($key));
        }
    }

    /**
     * Adjust a reservation once the actual cost is known (e.g. fewer results than requested).
     */
    public function adjust(string $key, int $deltaUnits): void
    {
        if ($deltaUnits === 0) {
            return;
        }

        $this->ensureRow($key);

        DB::table('source_usage')->where('source', $key)->where('date', $this->today())->update([
            'units' => DB::raw($deltaUnits > 0 ? 'units + '.$deltaUnits : 'GREATEST(0, CAST(units AS SIGNED) - '.abs($deltaUnits).')'),
            'updated_at' => now(),
        ]);
    }

    public function recordError(string $key): void
    {
        $this->ensureRow($key);

        DB::table('source_usage')->where('source', $key)->where('date', $this->today())
            ->update(['errors' => DB::raw('errors + 1'), 'updated_at' => now()]);
    }

    /**
     * The provider says the quota is gone (e.g. HTTP 429): stop for the rest of the day.
     */
    public function markExhausted(string $key): void
    {
        $this->ensureRow($key);

        DB::table('source_usage')->where('source', $key)->where('date', $this->today())
            ->update(['exhausted' => true, 'updated_at' => now()]);
    }

    public function isExhausted(string $key): bool
    {
        $usage = $this->usage($key);
        $limit = $this->dailyLimit($key);

        return $usage !== null && ($usage->exhausted || ($limit !== null && $usage->units >= $limit));
    }

    public function used(string $key): int
    {
        return $this->usage($key)->units ?? 0;
    }

    public function remaining(string $key): ?int
    {
        $limit = $this->dailyLimit($key);

        if ($limit === null) {
            return null;
        }

        return $this->usage($key)?->exhausted ? 0 : max(0, $limit - $this->used($key));
    }

    public function usage(string $key): ?SourceUsage
    {
        return SourceUsage::query()->where('source', $key)->where('date', $this->today())->first();
    }

    private function unit(string $key): string
    {
        return $this->sources->has($key) ? $this->sources->descriptor($key)->quotaUnit() : 'units';
    }

    private function ensureRow(string $key): void
    {
        DB::table('source_usage')->insertOrIgnore([
            'source' => $key,
            'date' => $this->today(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}

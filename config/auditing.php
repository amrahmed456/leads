<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Website audits run as queued jobs (database queue by default).
    |
    */

    'queue' => env('AUDIT_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    |
    | Only the homepage is fetched. The time budget caps the total time spent
    | on one audit across redirects and fallbacks; max_bytes caps how much of
    | the page is downloaded and analyzed (nothing is stored).
    |
    */

    'http' => [
        'user_agent' => env('AUDIT_USER_AGENT', 'Mozilla/5.0 (compatible; LeadsAudit/1.0; business website check)'),
        'connect_timeout' => (int) env('AUDIT_CONNECT_TIMEOUT', 8),
        'timeout' => (int) env('AUDIT_TIMEOUT', 15),
        'time_budget' => 45,
        'max_redirects' => 5,
        'max_bytes' => 1_500_000,
        // Analyze the page even when its certificate is invalid (recorded as a negative signal).
        'allow_invalid_ssl' => true,
        // When HTTPS cannot be reached at all, try plain HTTP once.
        'http_fallback' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries and Throttling
    |--------------------------------------------------------------------------
    |
    | Temporary failures (timeouts, 429, 5xx) are retried after the given
    | delays. per_minute caps audits across all workers.
    |
    */

    'retries' => [
        'max_attempts' => 3,
        'delays' => [300, 1800],
    ],

    'per_minute' => (int) env('AUDIT_PER_MINUTE', 20),

    /*
    |--------------------------------------------------------------------------
    | Freshness
    |--------------------------------------------------------------------------
    |
    | A recent successful audit of the same URL is reused instead of fetching
    | again; failed audits are retried only after a delay. "Re-audit" in the
    | UI bypasses freshness.
    |
    */

    'freshness' => [
        'completed_days' => (int) env('AUDIT_FRESH_DAYS', 30),
        'failed_retry_hours' => (int) env('AUDIT_RETRY_FAILED_AFTER_HOURS', 24),
        'stale_running_minutes' => 30,
        'stale_pending_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Score Weights
    |--------------------------------------------------------------------------
    |
    | The audit score is the share of applicable points earned, 0-100. Booking,
    | ordering and e-commerce only apply to categories that need them
    | (config/intelligence.php "category_needs").
    |
    */

    'score' => [
        'https' => 15,
        'mobile_viewport' => 15,
        'title' => 5,
        'meta_description' => 5,
        'phone' => 8,
        'email' => 4,
        'contact_form' => 8,
        'whatsapp' => 10,
        'online_booking' => 10,
        'online_ordering' => 10,
        'ecommerce' => 10,
        'languages' => 10,
        'maintained' => 5,
    ],

];

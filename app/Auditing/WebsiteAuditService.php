<?php

namespace App\Auditing;

use App\Auditing\Data\AuditScore;
use App\Auditing\Data\FetchResult;
use App\Auditing\Data\WebsiteAnalysis;
use App\Auditing\Exceptions\WebsiteFetchException;
use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Enums\Country;
use App\Enums\WebsiteSignal;
use App\Intelligence\CategoryNeeds;
use App\Models\WebsiteAudit;
use App\Support\Normalization\UrlNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs one website audit: fetch the homepage, analyze it, score it, store
 * the extracted facts (never the page itself).
 */
final class WebsiteAuditService
{
    /** Results copied when reusing a recent audit of the same URL. */
    private const RESULT_COLUMNS = [
        'final_url', 'http_status', 'response_time_ms', 'reachable', 'is_https', 'ssl_valid', 'redirect_count',
        'title', 'meta_description', 'primary_language', 'cms', 'has_viewport', 'has_phone', 'has_email',
        'has_whatsapp', 'has_contact_form', 'has_booking', 'has_online_ordering', 'has_ecommerce', 'has_cart',
        'has_arabic', 'has_english', 'signals', 'languages', 'technologies', 'score', 'score_breakdown',
    ];

    public function __construct(
        private readonly WebsiteFetcher $fetcher,
        private readonly WebsiteAnalyzer $analyzer,
        private readonly AuditScorer $scorer,
        private readonly CategoryNeeds $needs,
        private readonly UrlNormalizer $urls,
        private readonly int $freshDays,
    ) {}

    /**
     * @throws WebsiteFetchException When a temporary failure (timeout, 429, 5xx...) is worth retrying
     */
    public function run(WebsiteAudit $audit): void
    {
        $audit->loadMissing('business');
        $audit->markRunning();

        $url = $this->urls->normalize($audit->url);

        if ($url === null) {
            $this->fail($audit, new WebsiteFetchException(AuditFailure::InvalidUrl, AuditFailure::InvalidUrl->label().": {$audit->url}"));

            return;
        }

        // Branches of a chain often share one website: reuse a fresh audit instead of fetching again.
        $recent = $audit->forced ? null : $this->recentAuditOf($url, $audit);

        if ($recent !== null) {
            $audit->markCompleted([
                ...$recent->only(self::RESULT_COLUMNS),
                'normalized_url' => $url,
                'reused_audit_id' => $recent->getKey(),
                'pages_checked' => 0,
            ]);

            return;
        }

        try {
            $result = $this->fetcher->fetch($url);
            $this->ensureUsable($result);
        } catch (WebsiteFetchException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            $this->fail($audit, $e);

            return;
        }

        $business = $audit->business;
        $analysis = $this->analyzer->analyze(HtmlDocument::fromFetch($result), Country::tryFrom((string) $business->country_code));
        $score = $this->scorer->score($analysis, $result, $this->needs->forBusiness($business));

        $audit->markCompleted($this->results($url, $result, $analysis, $score));
    }

    /**
     * Record a definitive failure: permanent, or temporary with no retries left.
     */
    public function fail(WebsiteAudit $audit, WebsiteFetchException $e): void
    {
        $serverAnswered = $e->httpStatus !== null;

        $audit->markFailed($e->failure, $e->getMessage(), [
            'final_url' => $e->finalUrl !== null ? mb_substr($e->finalUrl, 0, 500) : null,
            'http_status' => $e->httpStatus,
            'reachable' => $serverAnswered,
            'pages_checked' => $serverAnswered ? 1 : 0,
        ]);
    }

    /**
     * Only a successful HTML response can be analyzed.
     *
     * @throws WebsiteFetchException
     */
    private function ensureUsable(FetchResult $result): void
    {
        if ($result->isSuccessful()) {
            if (! $result->isHtml()) {
                throw new WebsiteFetchException(
                    AuditFailure::NotHtml,
                    AuditFailure::NotHtml->label()." ({$result->contentType()}): {$result->finalUrl}",
                    $result->status,
                    $result->finalUrl,
                );
            }

            return;
        }

        $failure = match (true) {
            $result->status === 429 => AuditFailure::RateLimited,
            $result->status >= 500 => AuditFailure::ServerError,
            $result->status === 401, $result->status === 403 => AuditFailure::AccessDenied,
            $result->status === 404, $result->status === 410 => AuditFailure::NotFound,
            default => AuditFailure::ClientError,
        };

        $retryAfter = $result->headers['retry-after'] ?? null;

        throw new WebsiteFetchException(
            $failure,
            "{$failure->label()}: {$result->finalUrl}",
            $result->status,
            $result->finalUrl,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    private function recentAuditOf(string $url, WebsiteAudit $audit): ?WebsiteAudit
    {
        // The score depends on the category's needs, so only reuse audits made for the same category.
        $category = $audit->business->category;

        return WebsiteAudit::query()
            ->where('normalized_url', $url)
            ->where('status', AuditStatus::Completed)
            ->whereNull('reused_audit_id')
            ->whereKeyNot($audit->getKey())
            ->where('audited_at', '>=', now()->subDays($this->freshDays))
            ->whereHas('business', fn (Builder $query) => $category === null
                ? $query->whereNull('category')
                : $query->where('category', $category))
            ->latest('audited_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function results(string $url, FetchResult $result, WebsiteAnalysis $analysis, AuditScore $score): array
    {
        return [
            'normalized_url' => $url,
            'final_url' => mb_substr($result->finalUrl, 0, 500),
            'http_status' => $result->status,
            'response_time_ms' => $result->responseTimeMs,
            'reachable' => true,
            'is_https' => $result->isHttps(),
            'ssl_valid' => $result->isHttps() ? $result->sslValid : null,
            'redirect_count' => min(255, count($result->redirects)),
            'pages_checked' => 1,
            'title' => $analysis->title,
            'meta_description' => $analysis->metaDescription,
            'primary_language' => $analysis->languages->primary,
            'cms' => $analysis->cms,
            'has_viewport' => $analysis->has(WebsiteSignal::MobileViewport),
            'has_phone' => $analysis->has(WebsiteSignal::Phone),
            'has_email' => $analysis->has(WebsiteSignal::Email),
            'has_whatsapp' => $analysis->has(WebsiteSignal::Whatsapp),
            'has_contact_form' => $analysis->has(WebsiteSignal::ContactForm),
            'has_booking' => $analysis->has(WebsiteSignal::Booking),
            'has_online_ordering' => $analysis->has(WebsiteSignal::OnlineOrdering),
            'has_ecommerce' => $analysis->has(WebsiteSignal::Ecommerce),
            'has_cart' => $analysis->has(WebsiteSignal::Cart),
            'has_arabic' => $analysis->languages->hasArabic,
            'has_english' => $analysis->languages->hasEnglish,
            'signals' => $analysis->signalsToArray(),
            'languages' => $analysis->languages->toArray(),
            'technologies' => array_map(fn ($technology): array => $technology->toArray(), $analysis->technologies),
            'score' => $score->score,
            'score_breakdown' => $score->toArray(),
        ];
    }
}

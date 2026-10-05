<?php

namespace App\Console\Commands;

use App\Enums\AuditFailure;
use App\Enums\AuditStatus;
use App\Models\WebsiteAudit;
use Illuminate\Console\Command;

/**
 * An audit job runs for at most a couple of minutes. Audits still "running"
 * long after that lost their worker; audits "queued" for days were never
 * processed. Both are marked failed so new audits can be queued.
 */
class FailStaleAuditsCommand extends Command
{
    protected $signature = 'audits:fail-stale';

    protected $description = 'Mark website audits stuck in the queue or in progress as failed';

    public function handle(): int
    {
        $running = WebsiteAudit::query()
            ->where('status', AuditStatus::Running)
            ->where('started_at', '<', now()->subMinutes((int) config('auditing.freshness.stale_running_minutes')))
            ->get();

        $pending = WebsiteAudit::query()
            ->where('status', AuditStatus::Pending)
            ->where('queued_at', '<', now()->subHours((int) config('auditing.freshness.stale_pending_hours')))
            ->get();

        foreach ($running as $audit) {
            $audit->markFailed(AuditFailure::Unexpected, 'The audit stopped responding (the queue worker may have been stopped).');
        }

        foreach ($pending as $audit) {
            $audit->markFailed(AuditFailure::Unexpected, 'The audit was never processed. Check that the queue is being worked (queue worker or scheduler).');
        }

        $this->info("Marked {$running->count()} running and {$pending->count()} queued audit(s) as failed.");

        return self::SUCCESS;
    }
}

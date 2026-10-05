<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per audit run (history is kept). Only facts extracted from the
     * homepage are stored, never the page itself.
     */
    public function up(): void
    {
        Schema::create('website_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);

            // What was audited.
            $table->string('url', 500)->nullable();
            $table->string('normalized_url', 500)->nullable();
            $table->string('final_url', 500)->nullable();

            // Fetch outcome.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->boolean('reachable')->nullable();
            $table->boolean('is_https')->nullable();
            $table->boolean('ssl_valid')->nullable();
            $table->unsignedTinyInteger('redirect_count')->default(0);
            $table->unsignedTinyInteger('pages_checked')->default(0);

            // Page facts.
            $table->string('title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('primary_language', 10)->nullable();
            $table->string('cms', 50)->nullable();

            // Key signals as columns for filtering. NULL means "not analyzed".
            $table->boolean('has_viewport')->nullable();
            $table->boolean('has_phone')->nullable();
            $table->boolean('has_email')->nullable();
            $table->boolean('has_whatsapp')->nullable();
            $table->boolean('has_contact_form')->nullable();
            $table->boolean('has_booking')->nullable();
            $table->boolean('has_online_ordering')->nullable();
            $table->boolean('has_ecommerce')->nullable();
            $table->boolean('has_cart')->nullable();
            $table->boolean('has_arabic')->nullable();
            $table->boolean('has_english')->nullable();

            // Details: every signal with confidence and evidence, languages, technologies.
            $table->json('signals')->nullable();
            $table->json('languages')->nullable();
            $table->json('technologies')->nullable();

            // Explainable technical / digital presence score.
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('score_breakdown')->nullable();

            // Failures, retries and reuse.
            $table->string('failure_type', 30)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // Manual re-audits always fetch; others may reuse a fresh audit of the same URL.
            $table->boolean('forced')->default(false);
            $table->foreignId('reused_audit_id')->nullable()->constrained('website_audits')->nullOnDelete();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['normalized_url', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_audits');
    }
};

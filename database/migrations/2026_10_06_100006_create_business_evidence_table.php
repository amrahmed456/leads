<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per fact a source attests about a business (its phone, brand,
     * website, existence...). Conflicting values from different sources are
     * all kept with their provenance; reconciliation picks the preferred one
     * without deleting the others.
     */
    public function up(): void
    {
        Schema::create('business_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fact_type', 30);
            $table->string('value', 500);
            $table->string('value_normalized', 191);
            $table->string('provider', 50);
            $table->string('source_role', 30);
            $table->string('independence_group', 50);
            $table->string('source_record_id', 191)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('last_seen_at');
            // sha1(fact_type, provider, source_record_id, value_normalized): one row per attested value.
            $table->char('fingerprint', 40);
            $table->timestamps();

            $table->unique(['business_id', 'fingerprint']);
            $table->index(['business_id', 'fact_type', 'status']);
            $table->index(['fact_type', 'value_normalized']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_evidence');
    }
};

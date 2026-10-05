<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Opportunities are derived from a business and its latest audit; they are
     * recalculated whenever either changes. One row per business and type.
     */
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('priority', 10);
            $table->json('reasons');
            // The rule that detected it, so detection logic can evolve traceably.
            $table->string('rule', 50);
            $table->foreignId('website_audit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['business_id', 'type']);
            $table->index(['type', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};

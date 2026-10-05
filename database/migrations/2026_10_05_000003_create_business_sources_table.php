<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per provider record. The unique (provider, provider_id) index
     * guarantees a provider record can never be stored twice.
     */
    public function up(): void
    {
        Schema::create('business_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_id', 191);
            // The source that created the business; it may refresh the business fields.
            $table->boolean('is_primary')->default(false);
            $table->string('source_url', 500)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_sources');
    }
};

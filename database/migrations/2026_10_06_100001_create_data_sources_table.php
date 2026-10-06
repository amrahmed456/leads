<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Static source metadata lives in config/sources.php; these tables hold
     * what changes at runtime: the admin's settings, encrypted credentials,
     * health and daily usage.
     */
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            // NULL means "use the catalog default".
            $table->boolean('enabled')->nullable();
            // Encrypted at rest (encrypted:array cast); never sent to the browser.
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->string('health_status', 20)->default('unknown');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('source_usage', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            // Quota units consumed (credits for Geoapify, downloads for AllThePlaces...).
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->boolean('exhausted')->default(false);
            $table->timestamps();

            $table->unique(['source', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_usage');
        Schema::dropIfExists('data_sources');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('search_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('provider', 50);

            // Search area: a centre point (given by the user or geocoded from the city) and a radius.
            $table->char('country_code', 2);
            $table->string('city', 120);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('resolved_location', 500)->nullable();
            $table->unsignedInteger('radius_meters');

            $table->string('category', 50);
            $table->json('keywords')->nullable();

            // State and statistics of the latest discovery run.
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('results_count')->default(0);
            $table->unsignedInteger('new_businesses_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->boolean('results_truncated')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_campaigns');
    }
};

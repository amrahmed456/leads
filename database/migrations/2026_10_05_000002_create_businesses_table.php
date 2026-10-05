<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A business is the real-world entity. Where its data came from is
     * recorded separately in business_sources, so one business can be
     * linked to records from several providers.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('alternate_name')->nullable();
            $table->string('name_normalized')->index();

            $table->string('category', 50)->nullable()->index();
            $table->string('type', 100)->nullable();

            $table->char('country_code', 2)->nullable()->index();
            $table->string('city', 120)->nullable()->index();
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('email')->nullable();
            $table->json('social_links')->nullable();
            $table->string('opening_hours')->nullable();

            $table->decimal('rating', 3, 2)->nullable()->index();
            $table->unsignedInteger('reviews_count')->nullable()->index();

            $table->timestamp('first_discovered_at')->useCurrent()->index();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};

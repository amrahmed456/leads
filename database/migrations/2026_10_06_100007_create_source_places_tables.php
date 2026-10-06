<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A compact local index of places from file-based sources: bounded
     * Overture extracts and configured AllThePlaces spiders. Discovery and
     * identity analysis query it locally, so no global dataset is ever
     * downloaded or queried during a campaign.
     */
    public function up(): void
    {
        Schema::create('overture_imports', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150);
            // upload, remote, path, duckdb
            $table->string('mode', 20);
            // geojson, ndjson, csv
            $table->string('format', 20);
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->json('bbox')->nullable();
            $table->date('release_date')->nullable();
            $table->string('source_release', 50)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->string('remote_url', 1000)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            // Where the next chunk resumes in the file.
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->string('error_message', 2000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atp_spiders', function (Blueprint $table) {
            $table->id();
            $table->string('spider', 100)->unique();
            $table->string('brand_name', 150);
            $table->string('brand_wikidata', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->string('last_run_id', 50)->nullable();
            $table->string('output_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('feature_count')->default(0);
            $table->unsignedInteger('stored_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });

        Schema::create('source_places', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('source_record_id', 191);
            // The Overture import or AllThePlaces spider the record came from.
            $table->string('dataset_type', 30);
            $table->unsignedBigInteger('dataset_id')->nullable();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('brand_name', 150)->nullable();
            $table->string('brand_wikidata', 20)->nullable()->index();
            $table->string('operator', 150)->nullable();
            $table->string('branch', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('mapped_category', 50)->nullable();
            $table->json('taxonomy')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('operating_status', 30)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('email')->nullable();
            $table->json('socials')->nullable();
            $table->json('provenance')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->json('extra')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['source', 'source_record_id']);
            $table->index(['source', 'latitude', 'longitude']);
            $table->index(['dataset_type', 'dataset_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_places');
        Schema::dropIfExists('atp_spiders');
        Schema::dropIfExists('overture_imports');
    }
};

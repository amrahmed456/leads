<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Identity of a business, as concluded from its evidence: its brand, what
     * kind of entity it is and why, and how well it is corroborated. The
     * business keeps its own human-readable name; the brand is separate.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('brand_confidence')->nullable()->after('brand_id');
            $table->string('brand_source', 50)->nullable()->after('brand_confidence');

            $table->string('entity_type', 30)->nullable()->after('brand_source')->index();
            $table->unsignedTinyInteger('entity_confidence')->nullable()->after('entity_type');
            $table->json('entity_reason_codes')->nullable()->after('entity_confidence');
            $table->json('entity_reasons')->nullable()->after('entity_reason_codes');
            $table->timestamp('classified_at')->nullable()->after('entity_reasons');

            $table->string('operating_status', 30)->nullable()->after('opening_hours');
            $table->unsignedSmallInteger('evidence_count')->default(0)->after('reviews_count')->index();
            $table->unsignedTinyInteger('independent_source_count')->default(0)->after('evidence_count');
            $table->boolean('has_identity_conflict')->default(false)->after('independent_source_count');
            $table->json('identity_conflicts')->nullable()->after('has_identity_conflict');
            $table->timestamp('last_verified_at')->nullable()->after('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn([
                'brand_confidence', 'brand_source', 'entity_type', 'entity_confidence', 'entity_reason_codes',
                'entity_reasons', 'classified_at', 'operating_status', 'evidence_count', 'independent_source_count',
                'has_identity_conflict', 'identity_conflicts', 'last_verified_at',
            ]);
        });
    }
};

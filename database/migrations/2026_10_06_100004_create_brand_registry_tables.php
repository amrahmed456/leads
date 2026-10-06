<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The brand / organization graph. A business is a physical location (a
     * branch); it can belong to a brand, which can be owned by an
     * organization, which can have a parent organization. The edges are
     * stored in entity_relationships with their source and confidence.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('wikidata_id', 20)->nullable()->unique();
            // company, group, government, nonprofit, unknown
            $table->string('type', 20)->default('unknown');
            $table->char('country_code', 2)->nullable();
            $table->string('website', 500)->nullable();
            $table->unsignedInteger('employees')->nullable();
            $table->string('source', 30);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('wikidata_id', 20)->nullable()->unique();
            $table->string('nsi_id', 100)->nullable()->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 20)->default('unknown');
            // Forces an entity type for every branch (e.g. enterprise for banks).
            $table->string('entity_type', 30)->nullable();
            $table->json('countries')->nullable();
            $table->json('categories')->nullable();
            $table->json('official_domains')->nullable();
            // Locations seen in first-party / open datasets (AllThePlaces, Overture).
            $table->unsignedInteger('location_count')->nullable();
            $table->unsignedSmallInteger('country_count')->nullable();
            $table->string('source', 30);
            $table->json('metadata')->nullable();
            $table->timestamp('wikidata_fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brand_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 191);
            $table->string('alias_normalized', 191)->index();
            $table->string('source', 30);
            $table->timestamps();

            $table->unique(['brand_id', 'alias_normalized']);
        });

        // Cache of the Wikidata fields we use; looked up only for known IDs.
        Schema::create('wikidata_entities', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('label')->nullable();
            $table->string('label_ar')->nullable();
            $table->string('description', 500)->nullable();
            $table->json('aliases')->nullable();
            $table->string('official_website', 500)->nullable();
            $table->json('instance_of')->nullable();
            $table->json('parent_ids')->nullable();
            $table->json('country_ids')->nullable();
            $table->unsignedInteger('employees')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });

        Schema::create('entity_relationships', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('relationship', 30);
            $table->morphs('object');
            $table->unsignedTinyInteger('confidence');
            $table->string('provider', 50);
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'relationship', 'object_type', 'object_id'], 'entity_relationships_edge_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_relationships');
        Schema::dropIfExists('wikidata_entities');
        Schema::dropIfExists('brand_aliases');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('organizations');
    }
};

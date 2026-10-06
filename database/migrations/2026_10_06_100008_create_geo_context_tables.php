<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Geographic and address context (GeoNames cities, OpenAddresses points).
     * Neither is a business source; they only normalize and support matching.
     */
    public function up(): void
    {
        Schema::create('geo_places', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('geonames_id')->unique();
            $table->string('name', 200);
            $table->string('ascii_name', 200);
            $table->string('name_ar', 200)->nullable();
            // Normalized names (all languages), space-separated with a leading and trailing "|".
            $table->text('search_names');
            $table->char('country_code', 2)->index();
            $table->string('admin1', 20)->nullable();
            $table->string('feature_code', 10);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedBigInteger('population')->default(0);
            $table->timestamps();
        });

        Schema::create('address_points', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 100);
            $table->string('dataset', 100);
            $table->string('number', 50)->nullable();
            $table->string('street', 200)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();

            $table->unique(['dataset', 'source_id']);
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_points');
        Schema::dropIfExists('geo_places');
    }
};

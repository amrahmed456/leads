<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links businesses to every campaign that found them, so overlapping
     * campaigns share businesses instead of duplicating them.
     */
    public function up(): void
    {
        Schema::create('business_search_campaign', function (Blueprint $table) {
            $table->foreignId('search_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['search_campaign_id', 'business_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_search_campaign');
    }
};

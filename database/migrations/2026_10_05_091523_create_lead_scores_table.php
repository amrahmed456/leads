<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The current rule-based lead score of each business, with every point
     * explained in the breakdown.
     */
    public function up(): void
    {
        Schema::create('lead_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->index();
            $table->string('grade', 10);
            $table->json('breakdown');
            // Version of the scoring rules (config/intelligence.php) that produced the score.
            $table->string('version', 20);
            $table->timestamp('scored_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
    }
};

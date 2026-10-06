<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Lead qualification is now split into separate, explainable scores
     * (data confidence, business fit, opportunity) and a final eligibility
     * status. The old hot/warm/cold grade is replaced by that status; run
     * "php artisan businesses:recalculate-eligibility" after migrating.
     */
    public function up(): void
    {
        Schema::table('lead_scores', function (Blueprint $table) {
            $table->string('status', 20)->default('review_required')->after('score')->index();
            $table->unsignedTinyInteger('data_confidence_score')->default(0)->after('status')->index();
            $table->unsignedTinyInteger('business_fit_score')->default(0)->after('data_confidence_score')->index();
            $table->unsignedTinyInteger('opportunity_score')->default(0)->after('business_fit_score')->index();
            $table->json('eligibility_reasons')->nullable()->after('breakdown');
            $table->json('confidence_breakdown')->nullable()->after('eligibility_reasons');
            $table->json('fit_breakdown')->nullable()->after('confidence_breakdown');
            $table->json('opportunity_breakdown')->nullable()->after('fit_breakdown');
            $table->dropColumn('grade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_scores', function (Blueprint $table) {
            $table->string('grade', 10)->default('cold')->after('score');
            $table->dropColumn([
                'status', 'data_confidence_score', 'business_fit_score', 'opportunity_score',
                'eligibility_reasons', 'confidence_breakdown', 'fit_breakdown', 'opportunity_breakdown',
            ]);
        });
    }
};

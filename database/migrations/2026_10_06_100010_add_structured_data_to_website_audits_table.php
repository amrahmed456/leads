<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * First-party identity facts read from the homepage: Schema.org entities
     * (JSON-LD, microdata, RDFa) and links to corporate pages (branches,
     * franchise, careers, investors).
     */
    public function up(): void
    {
        Schema::table('website_audits', function (Blueprint $table) {
            $table->json('structured_data')->nullable()->after('technologies');
            $table->json('corporate_signals')->nullable()->after('structured_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('website_audits', function (Blueprint $table) {
            $table->dropColumn(['structured_data', 'corporate_signals']);
        });
    }
};

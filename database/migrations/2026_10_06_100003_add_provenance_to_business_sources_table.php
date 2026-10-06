<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every source record states which role it played, which independence
     * group its data comes from, how confident the source is, where the data
     * originally came from, and why it was matched to its business.
     */
    public function up(): void
    {
        Schema::table('business_sources', function (Blueprint $table) {
            $table->string('source_role', 30)->default('discovery')->after('provider');
            $table->string('independence_group', 50)->default('unknown')->after('source_role')->index();
            $table->decimal('confidence', 4, 3)->nullable()->after('is_primary');
            $table->json('provenance')->nullable()->after('confidence');
            $table->json('match_reasons')->nullable()->after('provenance');
        });

        DB::table('business_sources')->where('provider', 'openstreetmap')->update(['independence_group' => 'osm_family']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_sources', function (Blueprint $table) {
            $table->dropColumn(['source_role', 'independence_group', 'confidence', 'provenance', 'match_reasons']);
        });
    }
};

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
     * A campaign can now query several discovery sources. The original
     * "provider" column is kept (it holds the first source) so existing
     * campaigns keep working; campaigns without "sources" use it.
     */
    public function up(): void
    {
        Schema::table('search_campaigns', function (Blueprint $table) {
            $table->json('sources')->nullable()->after('provider');
            $table->string('preset', 30)->nullable()->after('sources');
        });

        DB::table('search_campaigns')->orderBy('id')->each(function (object $campaign): void {
            DB::table('search_campaigns')->where('id', $campaign->id)->update(['sources' => json_encode([$campaign->provider])]);
        });

        Schema::create('campaign_source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('results_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('merged_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->boolean('truncated')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_message', 2000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['search_campaign_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_source_runs');

        Schema::table('search_campaigns', function (Blueprint $table) {
            $table->dropColumn(['sources', 'preset']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('digests', function (Blueprint $table) {
            $table->boolean('is_weekly_digest')
                ->default(false)
                ->after('max_days');
            $table->string('week_starts_on')
                ->nullable()
                ->after('is_weekly_digest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digests', function (Blueprint $table) {
            $table->dropColumn(['is_weekly_digest', 'week_starts_on']);
        });
    }
};

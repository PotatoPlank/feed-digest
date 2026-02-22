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
            $table->boolean('only_prior_to_today')
                ->default(true)
                ->after('filters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digests', function (Blueprint $table) {
            $table->dropColumn('only_prior_to_today');
        });
    }
};

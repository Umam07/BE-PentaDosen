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
        Schema::table('scholar_publications', function (Blueprint $table) {
            if (!Schema::hasColumn('scholar_publications', 'is_corresponding')) {
                $table->boolean('is_corresponding')->default(false)->after('citations');
            }
            if (!Schema::hasColumn('scholar_publications', 'is_corresponding_confirmed')) {
                $table->boolean('is_corresponding_confirmed')->default(false)->after('is_corresponding');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholar_publications', function (Blueprint $table) {
            if (Schema::hasColumn('scholar_publications', 'is_corresponding')) {
                $table->dropColumn('is_corresponding');
            }
            if (Schema::hasColumn('scholar_publications', 'is_corresponding_confirmed')) {
                $table->dropColumn('is_corresponding_confirmed');
            }
        });
    }
};

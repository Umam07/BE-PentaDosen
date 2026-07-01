<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('penelitian', function (Blueprint $table) {
            $table->renameColumn('tahun', 'tahun_old');
        });

        Schema::table('penelitian', function (Blueprint $table) {
            $table->date('tahun')->nullable()->after('tahun_old');
        });

        // Migrate data
        DB::statement("UPDATE penelitian SET tahun = STR_TO_DATE(CONCAT(tahun_old, '-01-01'), '%Y-%m-%d') WHERE tahun_old IS NOT NULL AND tahun_old > 0");

        Schema::table('penelitian', function (Blueprint $table) {
            $table->dropColumn('tahun_old');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penelitian', function (Blueprint $table) {
            $table->renameColumn('tahun', 'tahun_new');
        });

        Schema::table('penelitian', function (Blueprint $table) {
            $table->integer('tahun')->nullable()->after('tahun_new');
        });

        // Migrate data back
        DB::statement("UPDATE penelitian SET tahun = YEAR(tahun_new) WHERE tahun_new IS NOT NULL");

        Schema::table('penelitian', function (Blueprint $table) {
            $table->dropColumn('tahun_new');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('UPDATE users SET total_kpi_points = ROUND(total_kpi_points)');
        DB::statement('UPDATE documents SET awarded_points = ROUND(awarded_points)');
        DB::statement('UPDATE penelitian SET awarded_points = ROUND(awarded_points)');
        DB::statement('UPDATE scopus_publications SET awarded_points = ROUND(awarded_points)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rounding is a lossy operation, down cannot be easily reverted to exact decimals.
    }
};

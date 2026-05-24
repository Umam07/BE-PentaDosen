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
        Schema::table('documents', function (Blueprint $table) {
            $table->text('catatan')->nullable()->after('awarded_points');
        });

        Schema::table('penelitian', function (Blueprint $table) {
            $table->text('catatan')->nullable()->after('awarded_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('catatan');
        });

        Schema::table('penelitian', function (Blueprint $table) {
            $table->dropColumn('catatan');
        });
    }
};

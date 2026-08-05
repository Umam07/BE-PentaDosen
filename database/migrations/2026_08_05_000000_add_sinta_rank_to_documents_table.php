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
            if (!Schema::hasColumn('documents', 'sinta_rank')) {
                $table->string('sinta_rank')->nullable();
            }
            if (!Schema::hasColumn('documents', 'is_sinta_confirmed')) {
                $table->boolean('is_sinta_confirmed')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'sinta_rank')) {
                $table->dropColumn('sinta_rank');
            }
            if (Schema::hasColumn('documents', 'is_sinta_confirmed')) {
                $table->dropColumn('is_sinta_confirmed');
            }
        });
    }
};

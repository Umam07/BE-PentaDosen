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
            if (!Schema::hasColumn('scholar_publications', 'author_role')) {
                $table->string('author_role')->nullable()->after('authors');
            }
            if (!Schema::hasColumn('scholar_publications', 'author_order')) {
                $table->integer('author_order')->nullable()->default(1)->after('author_role');
            }
            if (!Schema::hasColumn('scholar_publications', 'total_authors')) {
                $table->integer('total_authors')->nullable()->default(1)->after('author_order');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholar_publications', function (Blueprint $table) {
            if (Schema::hasColumn('scholar_publications', 'total_authors')) {
                $table->dropColumn('total_authors');
            }
            if (Schema::hasColumn('scholar_publications', 'author_order')) {
                $table->dropColumn('author_order');
            }
            if (Schema::hasColumn('scholar_publications', 'author_role')) {
                $table->dropColumn('author_role');
            }
        });
    }
};

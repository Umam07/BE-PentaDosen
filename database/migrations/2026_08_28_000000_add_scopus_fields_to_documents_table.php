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
            if (!Schema::hasColumn('documents', 'total_authors')) {
                $table->integer('total_authors')->nullable()->default(1)->after('author_order');
            }
            if (!Schema::hasColumn('documents', 'subtype')) {
                $table->string('subtype')->nullable()->default('Article')->after('category');
            }
            if (!Schema::hasColumn('documents', 'journal')) {
                $table->string('journal')->nullable()->after('title');
            }
            if (!Schema::hasColumn('documents', 'doi')) {
                $table->string('doi')->nullable()->after('journal');
            }
            if (!Schema::hasColumn('documents', 'authors')) {
                $table->text('authors')->nullable()->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['total_authors', 'subtype', 'journal', 'doi', 'authors'] as $col) {
                if (Schema::hasColumn('documents', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

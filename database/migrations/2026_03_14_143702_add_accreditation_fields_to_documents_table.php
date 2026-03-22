<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('published_at')->nullable()->after('file_url');
            $table->boolean('is_kpi_counted')->default(false)->after('published_at');
            $table->string('accreditation_period')->nullable()->after('is_kpi_counted');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'is_kpi_counted', 'accreditation_period']);
        });
    }
};

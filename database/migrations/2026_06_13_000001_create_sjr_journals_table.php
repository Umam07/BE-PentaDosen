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
        Schema::create('sjr_journals', function (Blueprint $table) {
            $table->id();
            $table->string('issn')->unique()->index();
            $table->string('title');
            $table->string('quartile'); // Q1, Q2, Q3, Q4
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sjr_journals');
    }
};

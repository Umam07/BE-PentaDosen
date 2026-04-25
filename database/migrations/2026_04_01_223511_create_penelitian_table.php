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
        Schema::create('penelitian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('judul_penelitian');
            $table->bigInteger('dana_disetujui')->default(0);
            $table->string('program'); // hibah dikti, hibah internal, hibah luar negeri
            $table->string('skema'); // kompetisi, pembinaan
            $table->string('fokus'); // kesehatan, ekonomi
            $table->integer('tahun')->nullable();
            $table->string('file_url');
            $table->string('status')->default('Pending');
            $table->double('awarded_points', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penelitian');
    }
};

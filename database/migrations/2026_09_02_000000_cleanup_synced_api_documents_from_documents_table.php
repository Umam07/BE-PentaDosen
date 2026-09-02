<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Document;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Delete all auto-synced Google Scholar records from documents table
        Document::where('category', 'Google Scholar')->delete();

        // 2. Delete auto-synced placeholder documents where file_url is empty and no valid upload was made
        Document::where(function ($q) {
            $q->whereNull('file_url')
              ->orWhere('file_url', '')
              ->orWhere('file_url', '-');
        })
        ->whereIn('category', ['Google Scholar', 'Jurnal Internasional', 'Jurnal Nasional'])
        ->whereDoesntHave('history', function ($q) {
            $q->where('action', 'like', '%Diunggah%')
              ->orWhere('action', 'like', '%Didaftarkan%');
        })
        ->delete();

        // 3. Recalculate total KPI points for all users
        $users = User::all();
        foreach ($users as $user) {
            $user->recalculateKpiPoints();
        }

        // 4. Flush cache
        if (\Illuminate\Support\Facades\Cache::supportsTags()) {
            \Illuminate\Support\Facades\Cache::tags(['stats', 'leaderboard', 'lecturers', 'admin_documents', 'documents'])->flush();
        } else {
            \Illuminate\Support\Facades\Cache::flush();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-reversible cleanup
    }
};

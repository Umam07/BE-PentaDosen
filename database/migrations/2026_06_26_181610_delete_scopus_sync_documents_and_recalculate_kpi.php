<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Document;
use App\Models\User;
use App\Models\Penelitian;
use App\Models\ScopusPublication;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Delete all documents synced from Scopus (where category is Jurnal Internasional and no file has been uploaded)
        Document::where('category', 'Jurnal Internasional')
            ->where(function ($query) {
                $query->where('file_url', '')
                      ->orWhere('file_url', '-')
                      ->orWhereNull('file_url');
            })
            ->delete();

        // 2. Recalculate total KPI points for all users
        $users = User::all();
        foreach ($users as $user) {
            $totalDocPoints = Document::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $totalPenPoints = Penelitian::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $totalScopusPoints = ScopusPublication::where('user_id', $user->id)
                ->sum('awarded_points');

            $user->update(['total_kpi_points' => $totalDocPoints + $totalPenPoints + $totalScopusPoints]);
        }

        // 3. Clear Cache
        Cache::flush();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse since this is a data cleanup
    }
};

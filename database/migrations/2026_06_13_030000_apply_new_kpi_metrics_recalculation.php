<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Document;
use App\Models\Penelitian;
use App\Models\ScholarPublication;
use App\Models\ScopusPublication;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update PointWeights in the database
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Paten Sederhana'], ['weight_value' => 28]);
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Merk'], ['weight_value' => 12]);
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Merek'], ['weight_value' => 12]);

        // 2. Process each user to recalculate points
        $users = User::all();

        foreach ($users as $user) {
            // A. Update Synced Scholar Publications in 'documents' table
            // Find all synced scholar publications (previously under 'Jurnal Nasional' with file_url = '')
            $syncedScholarDocs = Document::where('user_id', $user->id)
                ->where('category', 'Jurnal Nasional')
                ->where(function($q) {
                    $q->where('file_url', '')->orWhere('file_url', '-')->orWhereNull('file_url');
                })
                ->get();

            // Also find all Scopus titles for cross-indexing check
            $scopusTitles = ScopusPublication::where('user_id', $user->id)
                ->pluck('title')
                ->map(fn($t) => strtolower(preg_replace('/[^a-z0-9]/', '', $t)))
                ->toArray();

            foreach ($syncedScholarDocs as $doc) {
                // Find matching scholar publication record to get citations
                $titleNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $doc->title));
                $scholarPub = ScholarPublication::where('user_id', $user->id)
                    ->get()
                    ->first(function($p) use ($titleNorm) {
                        return strtolower(preg_replace('/[^a-z0-9]/', '', $p->title)) === $titleNorm;
                    });

                $citations = $scholarPub ? (int)$scholarPub->citations : 0;
                
                // Check if cross-indexed
                $isAlsoScopus = in_array($titleNorm, $scopusTitles);

                $points = 0;
                if (!$isAlsoScopus) {
                    $points = 0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25;
                }

                $doc->update([
                    'category' => 'Google Scholar',
                    'awarded_points' => $points,
                ]);
            }

            // B. Recalculate manually uploaded HKI and Buku documents points
            $hkiAndBukuDocs = Document::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->where(function($q) {
                    $q->where('category', 'like', 'HKI%')
                      ->orWhere('category', 'like', 'Buku%');
                })
                ->get();

            // We need to group HKI Hak Cipta by year to enforce max 2/tahun limit
            $hakCiptaByYear = [];

            foreach ($hkiAndBukuDocs as $doc) {
                if ($doc->category === 'HKI Hak Cipta') {
                    $year = $doc->published_at ? Carbon::parse($doc->published_at)->year : null;
                    if ($year) {
                        $hakCiptaByYear[$year][] = $doc;
                    }
                } else {
                    $weight = DB::table('point_weights')->where('category', $doc->category)->first();
                    $points = $weight ? (double)$weight->weight_value : 0.0;
                    if (!$doc->is_kpi_counted) {
                        $points = 0.0;
                    }
                    $doc->update(['awarded_points' => $points]);
                }
            }

            // Enforce max 2/tahun for HKI Hak Cipta
            foreach ($hakCiptaByYear as $year => $docs) {
                // Sort by ID to process chronologically
                usort($docs, fn($a, $b) => $a->id - $b->id);
                foreach ($docs as $index => $doc) {
                    $points = 0.0;
                    if ($index < 2 && $doc->is_kpi_counted) {
                        $points = 5.0; // HKI Hak Cipta weight is 5
                    }
                    $doc->update(['awarded_points' => $points]);
                }
            }

            // C. Recalculate Penelitian points
            $penelitianList = Penelitian::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->get();

            foreach ($penelitianList as $pen) {
                $points = 0.0;
                if ($pen->program === 'hibah luar negeri') {
                    $points = 10.0;
                } elseif ($pen->program === 'hibah dikti') {
                    $points = 6.0;
                } elseif ($pen->program === 'hibah internal') {
                    $points = 3.0;
                }
                
                $pen->update(['awarded_points' => $points]);
            }

            // D. Finally, recalculate and update user's total_kpi_points
            $totalDocPoints = Document::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $totalPenPoints = Penelitian::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $user->update(['total_kpi_points' => $totalDocPoints + $totalPenPoints]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore old PointWeights
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Paten Sederhana'], ['weight_value' => 20]);
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Merk'], ['weight_value' => 5]);
        DB::table('point_weights')->updateOrInsert(['category' => 'HKI Merek'], ['weight_value' => 5]);

        // Revert categories and points
        $users = User::all();
        foreach ($users as $user) {
            // Restore Google Scholar documents back to Jurnal Nasional with default weight (20)
            $scholarDocs = Document::where('user_id', $user->id)
                ->where('category', 'Google Scholar')
                ->get();

            foreach ($scholarDocs as $doc) {
                $doc->update([
                    'category' => 'Jurnal Nasional',
                    'awarded_points' => 20.0
                ]);
            }

            // Recalculate penelitian back to old values
            $penelitianList = Penelitian::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->get();

            foreach ($penelitianList as $pen) {
                $points = 0.0;
                if ($pen->program === 'hibah luar negeri') {
                    $points = 60.0;
                } elseif ($pen->program === 'hibah dikti') {
                    $points = 50.0;
                } elseif ($pen->program === 'hibah internal') {
                    $points = 40.0;
                }
                $jutaRupiah = $pen->dana_disetujui / 1000000;
                $points += $jutaRupiah * 0.05;

                $pen->update(['awarded_points' => $points]);
            }

            // Restore other documents
            $docs = Document::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->get();

            foreach ($docs as $doc) {
                if ($doc->category === 'HKI Hak Cipta') {
                    $doc->update(['awarded_points' => 5.0]);
                } else {
                    $weight = DB::table('point_weights')->where('category', $doc->category)->first();
                    $doc->update(['awarded_points' => $weight ? (double)$weight->weight_value : 0.0]);
                }
            }

            // Recalculate total_kpi_points
            $totalDocPoints = Document::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $totalPenPoints = Penelitian::where('user_id', $user->id)
                ->where('status', 'Approved')
                ->sum('awarded_points');

            $user->update(['total_kpi_points' => $totalDocPoints + $totalPenPoints]);
        }
    }
};

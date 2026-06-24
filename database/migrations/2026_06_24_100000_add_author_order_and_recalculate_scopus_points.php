<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Document;
use App\Models\Penelitian;
use App\Models\ScopusPublication;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add author_order column to scopus_publications table
        if (!Schema::hasColumn('scopus_publications', 'author_order')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->integer('author_order')->nullable()->after('author_role');
            });
        }

        // 2. Add author_order column to documents table
        if (!Schema::hasColumn('documents', 'author_order')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->integer('author_order')->nullable()->after('author_role');
            });
        }

        // 3. Seed/Ensure correct point weights in the database
        $weights = [
            'Scopus Article (Single Author)' => 40,
            'Scopus Article Q1 (First Author)' => 24,
            'Scopus Article Q2 (First Author)' => 22,
            'Scopus Article Q3 (First Author)' => 20,
            'Scopus Article Q4 (First Author)' => 18,
            'Scopus Article Hyperauthor (First Author)' => 24,
            'Scopus Article Q1 (Member Author)' => 16,
            'Scopus Article Q2 (Member Author)' => 14,
            'Scopus Article Q3 (Member Author)' => 12,
            'Scopus Article Q4 (Member Author)' => 10,
            'Scopus Article Hyperauthor (Member Author)' => 1,
            'Scopus Non Article (Single Author)' => 30,
            'Scopus Non Article (First Author)' => 18,
            'Scopus Non Article (Member Author)' => 12,
            'Scopus Citation Per Author Number' => 1,
            'Scopus Document Tersitasi' => 5,
        ];

        foreach ($weights as $category => $value) {
            DB::table('point_weights')->updateOrInsert(
                ['category' => $category],
                ['weight_value' => $value, 'updated_at' => now()]
            );
        }

        // 4. Recalculate points for all existing Scopus publications
        $publications = ScopusPublication::all();
        foreach ($publications as $pub) {
            $role = $pub->author_role === 'Member Author' || $pub->author_role === 'Co-Author' ? 'Member Author' : ($pub->author_role ?: 'Member Author');
            $totalAuthors = (int)($pub->total_authors ?: 1);
            $isHyper = (bool)($pub->is_hyperauthor || $totalAuthors > 16);
            $q = in_array($pub->quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $pub->quartile : 'None';
            
            $subtype = $pub->subtype;
            $isArticle = true;
            if ($subtype && strtolower($subtype) !== 'ar' && strtolower($subtype) !== 'article') {
                $isArticle = false;
            }

            // Calculate points
            $points = 0.0;
            if ($isArticle) {
                if ($isHyper) {
                    if ($role === 'Single Author') $points = 40.0;
                    elseif ($role === 'First Author') $points = 24.0;
                    else $points = 1.0;
                } elseif ($role === 'Single Author') {
                    $points = 40.0;
                } elseif ($role === 'First Author') {
                    if ($q === 'Q1') $points = 24.0;
                    elseif ($q === 'Q2') $points = 22.0;
                    elseif ($q === 'Q3') $points = 20.0;
                    else $points = 18.0; // Q4 or None
                } else {
                    $n = max(1, $totalAuthors - 1);
                    if ($q === 'Q1') $points = 16.0 / $n;
                    elseif ($q === 'Q2') $points = 14.0 / $n;
                    elseif ($q === 'Q3') $points = 12.0 / $n;
                    else $points = 10.0 / $n; // Q4 or None
                }
            } else {
                // Non-Article
                if ($role === 'Single Author') {
                    $points = 30.0;
                } elseif ($role === 'First Author') {
                    $points = 18.0;
                } else {
                    $n = max(1, $totalAuthors - 1);
                    $points = 12.0 / $n;
                }
            }

            // Determine author order smart default
            $authorOrder = null;
            if ($role === 'Single Author' || $role === 'First Author') {
                $authorOrder = 1;
            }

            // Update publication
            $pub->update([
                'awarded_points' => round($points, 2),
                'author_order' => $authorOrder,
                'author_role' => $role,
            ]);

            // Update corresponding Document in 'documents' table if exists
            $doc = Document::where('user_id', $pub->user_id)
                ->where('title', $pub->title)
                ->where('category', 'Jurnal Internasional')
                ->first();

            if ($doc) {
                $doc->update([
                    'awarded_points' => round($points, 2),
                    'author_order' => $authorOrder,
                    'author_role' => $role,
                    'quartile' => $pub->quartile,
                    'is_hyperauthor' => $isHyper,
                ]);
            }
        }

        // 5. Recalculate total kpi points for all users
        $users = User::all();
        foreach ($users as $user) {
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
        // Remove columns
        if (Schema::hasColumn('scopus_publications', 'author_order')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->dropColumn('author_order');
            });
        }

        if (Schema::hasColumn('documents', 'author_order')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('author_order');
            });
        }
    }
};

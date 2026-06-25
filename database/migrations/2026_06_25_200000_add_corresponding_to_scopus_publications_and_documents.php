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
        // 1. Add columns to scopus_publications table individually
        if (!Schema::hasColumn('scopus_publications', 'is_corresponding')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->boolean('is_corresponding')->default(false)->after('author_order');
            });
        }
        
        if (!Schema::hasColumn('scopus_publications', 'is_corresponding_confirmed')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->boolean('is_corresponding_confirmed')->default(false)->after('is_corresponding');
            });
        }

        // 2. Add columns to documents table individually
        if (!Schema::hasColumn('documents', 'is_corresponding')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->boolean('is_corresponding')->default(false)->after('author_role');
            });
        }

        if (!Schema::hasColumn('documents', 'is_corresponding_confirmed')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->boolean('is_corresponding_confirmed')->default(false)->after('is_corresponding');
            });
        }

        // Ensure author_order exists and is nullable on documents table
        if (Schema::hasColumn('documents', 'author_order')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->integer('author_order')->nullable()->change();
            });
        }

        // 3. Initialize default values and recalculate all existing Scopus publications
        $publications = ScopusPublication::all();
        foreach ($publications as $pub) {
            $role = $pub->author_role === 'Member Author' || $pub->author_role === 'Co-Author' ? 'Member Author' : ($pub->author_role ?: 'Member Author');
            $totalAuthors = (int)($pub->total_authors ?: 1);
            $authorOrder = (int)($pub->author_order ?: ($role === 'First Author' || $role === 'Single Author' ? 1 : 2));
            $isHyper = (bool)($pub->is_hyperauthor || $totalAuthors > 16);
            $q = in_array($pub->quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $pub->quartile : 'None';
            
            $subtype = $pub->subtype;
            $isArticle = true;
            if ($subtype && strtolower($subtype) !== 'ar' && strtolower($subtype) !== 'article') {
                $isArticle = false;
            }

            // Default is_corresponding: true if first author or single author or order is 1
            $isCorresponding = ($role === 'First Author' || $role === 'Single Author' || $authorOrder === 1);
            $isCorrespondingConfirmed = false; // Requires confirmation

            // Calculate points using the new percentage formula
            $points = 0.0;
            if (!$isArticle) {
                // Non-Article: Single = 30, First = 18, Member = 12 / n
                if ($role === 'Single Author') {
                    $points = 30.0;
                } elseif ($role === 'First Author') {
                    $points = 18.0;
                } else {
                    $n = max(1, $totalAuthors - 1);
                    $points = 12.0 / $n;
                }
            } elseif ($isHyper) {
                // Hyperauthor flat rates
                if ($role === 'Single Author') {
                    $points = 40.0;
                } elseif ($role === 'First Author') {
                    $points = 24.0;
                } else {
                    $points = 1.0;
                }
            } else {
                // Base SKS points
                $basePointsMap = [
                    'Q1' => 40.0,
                    'Q2' => 38.0,
                    'Q3' => 35.0,
                    'Q4' => 33.0,
                    'None' => 33.0
                ];
                $basePoints = $basePointsMap[$q] ?? 33.0;

                if ($totalAuthors === 1 || ($authorOrder === 1 && $totalAuthors === 1)) {
                    $points = $basePoints;
                } elseif ($totalAuthors === 2) {
                    if ($authorOrder === 1) {
                        $points = $isCorresponding ? (0.6 * $basePoints) : (0.5 * $basePoints);
                    } else {
                        $points = $isCorresponding ? (0.5 * $basePoints) : (0.4 * $basePoints);
                    }
                } else {
                    // > 2 Authors
                    if ($authorOrder === 1) {
                        $points = $isCorresponding ? (0.6 * $basePoints) : (0.4 * $basePoints);
                    } else {
                        // User is a member author (2nd, 3rd, etc.)
                        if ($isCorresponding) {
                            $points = 0.4 * $basePoints;
                        } else {
                            // Default is Scenario 1: First Author is corresponding, so members get 40% / (n - 1)
                            $points = (0.4 * $basePoints) / ($totalAuthors - 1);
                        }
                    }
                }
            }

            // Update publication
            $pub->update([
                'is_corresponding' => $isCorresponding,
                'is_corresponding_confirmed' => $isCorrespondingConfirmed,
                'awarded_points' => round($points, 2),
            ]);

            // Update corresponding Document in 'documents' table if exists
            $doc = Document::where('user_id', $pub->user_id)
                ->where('title', $pub->title)
                ->where('category', 'Jurnal Internasional')
                ->first();

            if ($doc) {
                $doc->update([
                    'is_corresponding' => $isCorresponding,
                    'is_corresponding_confirmed' => $isCorrespondingConfirmed,
                    'awarded_points' => round($points, 2),
                    'author_order' => $authorOrder,
                ]);
            }
        }

        // 4. Recalculate total kpi points for all users
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
        if (Schema::hasColumn('scopus_publications', 'is_corresponding')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->dropColumn('is_corresponding');
            });
        }

        if (Schema::hasColumn('scopus_publications', 'is_corresponding_confirmed')) {
            Schema::table('scopus_publications', function (Blueprint $table) {
                $table->dropColumn('is_corresponding_confirmed');
            });
        }

        if (Schema::hasColumn('documents', 'is_corresponding')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('is_corresponding');
            });
        }

        if (Schema::hasColumn('documents', 'is_corresponding_confirmed')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropColumn('is_corresponding_confirmed');
            });
        }
    }
};

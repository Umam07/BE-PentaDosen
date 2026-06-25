<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScopusPublication extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'authors',
        'journal',
        'year',
        'citations',
        'doi',
        'quartile',
        'author_role',
        'is_hyperauthor',
        'awarded_points',
        'subtype',
        'total_authors',
        'is_corresponding',
        'is_corresponding_confirmed',
    ];

    protected $casts = [
        'is_hyperauthor' => 'boolean',
        'awarded_points' => 'double',
        'total_authors' => 'integer',
        'is_corresponding' => 'boolean',
        'is_corresponding_confirmed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate points based on the new KPI metrics.
     *
     * @param array|null $weights
     * @return float
     */
    public function calculatePoints(array $weights = null): float
    {
        if (!$weights) {
            $weights = \App\Models\PointWeight::pluck('weight_value', 'category')->toArray();
        }

        $totalAuthors = (int)($this->total_authors ?: 1);
        $authorOrder = (int)($this->author_order ?: ($this->author_role === 'First Author' || $this->author_role === 'Single Author' ? 1 : 2));
        $isCorresponding = (bool)$this->is_corresponding;
        $isHyper = (bool)($this->is_hyperauthor || $totalAuthors > 16);
        $q = in_array($this->quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $this->quartile : 'None';

        $subtype = $this->subtype;
        $isArticle = true;
        if ($subtype && strtolower($subtype) !== 'ar' && strtolower($subtype) !== 'article') {
            $isArticle = false;
        }

        if (!$isArticle) {
            // Non-Article: Single = 30, First = 18, Member = 12 / n
            if ($this->author_role === 'Single Author') {
                return 30.0;
            } elseif ($this->author_role === 'First Author') {
                return 18.0;
            } else {
                $n = max(1, $totalAuthors - 1);
                return 12.0 / $n;
            }
        }

        if ($isHyper) {
            if ($this->author_role === 'Single Author') {
                return 40.0;
            } elseif ($this->author_role === 'First Author') {
                return 24.0;
            } else {
                return 1.0;
            }
        }

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
            return $basePoints;
        }

        if ($totalAuthors === 2) {
            if ($authorOrder === 1) {
                return $isCorresponding ? (0.6 * $basePoints) : (0.5 * $basePoints);
            } else {
                return $isCorresponding ? (0.5 * $basePoints) : (0.4 * $basePoints);
            }
        }

        // > 2 Authors
        if ($authorOrder === 1) {
            return $isCorresponding ? (0.6 * $basePoints) : (0.4 * $basePoints);
        } else {
            // Member Author (2nd, 3rd, etc.)
            if ($isCorresponding) {
                return 0.4 * $basePoints;
            } else {
                // Default is Scenario 1: First Author is corresponding, so members get 40% / (n - 1)
                return (0.4 * $basePoints) / ($totalAuthors - 1);
            }
        }
    }
}

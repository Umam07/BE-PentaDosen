<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'user_id',
        'penelitian_id',
        'title',
        'category',
        'file_url',
        'published_at',
        'is_kpi_counted',
        'accreditation_period',
        'status',
        'awarded_points',
        'catatan',
        'quartile',
        'author_role',
        'is_hyperauthor',
        'author_order',
        'is_corresponding',
        'is_corresponding_confirmed',
        'hki_type',
        'inventor_name',
    ];

    protected $casts = [
        'published_at' => 'date',
        'is_kpi_counted' => 'boolean',
        'is_hyperauthor' => 'boolean',
        'is_corresponding' => 'boolean',
        'is_corresponding_confirmed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function penelitian()
    {
        return $this->belongsTo(Penelitian::class);
    }

    public function history()
    {
        return $this->hasMany(DocumentHistory::class)->orderBy('created_at', 'asc');
    }

    public function calculatePoints(): float
    {
        $category = $this->category;
        if ($category !== 'Jurnal Internasional' && $category !== 'Jurnal Nasional') {
            $weight = \App\Models\PointWeight::where('category', $category)->first();
            return $weight ? (float)$weight->weight_value : 0.0;
        }

        $totalAuthors = (int)($this->total_authors ?: 1);
        $authorOrder = (int)($this->author_order ?: ($this->author_role === 'First Author' || $this->author_role === 'Single Author' ? 1 : 2));
        $isCorresponding = (bool)$this->is_corresponding;
        $isHyper = (bool)($this->is_hyperauthor || $totalAuthors > 16);

        if ($category === 'Jurnal Internasional') {
            $q = in_array($this->quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $this->quartile : 'None';
            $basePointsMap = [
                'Q1' => 40.0,
                'Q2' => 38.0,
                'Q3' => 35.0,
                'Q4' => 33.0,
                'None' => 33.0
            ];
            $basePoints = $basePointsMap[$q] ?? 33.0;
        } else {
            // Jurnal Nasional base weight is 20
            $basePoints = 20.0;
        }

        if ($this->author_role === 'Single Author' || ($totalAuthors === 1)) {
            return $basePoints;
        }

        if ($isHyper) {
            if ($this->author_role === 'Single Author') {
                return $basePoints;
            } elseif ($this->author_role === 'First Author') {
                return 0.6 * $basePoints;
            } else {
                return 1.0;
            }
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
            if ($isCorresponding) {
                return 0.4 * $basePoints;
            } else {
                return (0.4 * $basePoints) / ($totalAuthors - 1);
            }
        }
    }
}

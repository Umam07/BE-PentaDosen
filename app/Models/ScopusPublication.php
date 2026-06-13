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
    ];

    protected $casts = [
        'is_hyperauthor' => 'boolean',
        'awarded_points' => 'double',
        'total_authors' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

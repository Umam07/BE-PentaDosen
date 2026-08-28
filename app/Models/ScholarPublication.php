<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarPublication extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'authors',
        'author_role',
        'author_order',
        'total_authors',
        'journal',
        'year',
        'citations',
        'is_corresponding',
        'is_corresponding_confirmed',
    ];

    protected $casts = [
        'author_order' => 'integer',
        'total_authors' => 'integer',
        'is_corresponding' => 'boolean',
        'is_corresponding_confirmed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

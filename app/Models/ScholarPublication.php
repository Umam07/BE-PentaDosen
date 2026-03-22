<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarPublication extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'authors',
        'journal',
        'year',
        'citations',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

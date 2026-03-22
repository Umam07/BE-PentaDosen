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
        'doi'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

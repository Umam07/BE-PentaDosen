<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScholarData extends Model
{
    protected $fillable = [
        'user_id',
        'thumbnail',
        'total_citations',
        'h_index',
        'i10_index',
        'last_synced',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

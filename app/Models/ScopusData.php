<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScopusData extends Model
{
    protected $table = 'scopus_data';
    
    protected $fillable = [
        'user_id',
        'document_count',
        'total_citations',
        'h_index',
        'last_synced',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

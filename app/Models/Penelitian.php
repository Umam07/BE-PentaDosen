<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Penelitian extends Model
{
    protected $table = 'penelitian';

    protected $fillable = [
        'user_id',
        'judul_penelitian',
        'dana_disetujui',
        'program',
        'skema',
        'fokus',
        'tahun',
        'file_url',
        'status',
        'awarded_points',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

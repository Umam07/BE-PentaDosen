<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'category',
        'file_url',
        'published_at',
        'is_kpi_counted',
        'accreditation_period',
        'status',
        'awarded_points',
    ];

    protected $casts = [
        'published_at' => 'date',
        'is_kpi_counted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $fillable = ['type', 'file_name', 'file_url', 'uploaded_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];
}

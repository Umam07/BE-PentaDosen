<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'penelitian_id',
        'user_id',
        'action',
        'notes',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function penelitian()
    {
        return $this->belongsTo(Penelitian::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'description'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public static function log($userId, $action, $description = null)
    {
        self::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointWeight extends Model
{
    protected $primaryKey = 'category';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'category',
        'weight_value',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Helper to get setting value with optional fallback.
     */
     public static function getValue(string $key, $default = null)
     {
         $setting = self::find($key);
         return $setting ? $setting->value : $default;
     }

     /**
      * Helper to set setting value.
      */
     public static function setValue(string $key, $value)
     {
         return self::updateOrCreate(['key' => $key], ['value' => $value]);
     }
}

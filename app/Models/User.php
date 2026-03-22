<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'scholar_id',
        'scopus_id',
        'total_kpi_points',
        'program_studi',
        'fakultas',
    ];

    public function scholarData()
    {
        return $this->hasOne(ScholarData::class);
    }

    public function scopusData()
    {
        return $this->hasOne(ScopusData::class);
    }

    public function publications()
    {
        return $this->hasMany(ScholarPublication::class);
    }

    public function scopusPublications()
    {
        return $this->hasMany(ScopusPublication::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

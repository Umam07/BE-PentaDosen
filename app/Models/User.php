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
        'penta_id',
        'total_kpi_points',
        'program_studi',
        'fakultas',
        'avatar',
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

    public function penelitian()
    {
        return $this->hasMany(Penelitian::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    public function recalculateKpiPoints()
    {
        $kpiStart = \App\Models\SystemSetting::getValue('kpi_period_start', '2026-01-01');
        $kpiEnd = \App\Models\SystemSetting::getValue('kpi_period_end', '2026-12-31');
        
        $kpiYearStart = \Carbon\Carbon::parse($kpiStart)->year;
        $kpiYearEnd = \Carbon\Carbon::parse($kpiEnd)->year;

        // Dynamic update of is_kpi_counted flag for all documents
        $periodStart = \Carbon\Carbon::parse($kpiStart);
        $periodEnd = \Carbon\Carbon::parse($kpiEnd);

        foreach ($this->documents as $doc) {
            if ($doc->published_at) {
                $publishedAt = \Carbon\Carbon::parse($doc->published_at);
                $isKpi = $publishedAt->between($periodStart, $periodEnd);
                if ((bool)$doc->is_kpi_counted !== $isKpi) {
                    $doc->update(['is_kpi_counted' => $isKpi]);
                }
            }
        }

        // A. Document points within KPI period (status = Approved and is_kpi_counted = true)
        // Internal manual documents only (excluding Google Scholar category if any)
        $totalDocPoints = $this->documents()
            ->where('status', 'Approved')
            ->where('is_kpi_counted', true)
            ->where('category', '!=', 'Google Scholar')
            ->sum('awarded_points');

        // B. Penelitian points within KPI period (status = Approved and year matches the KPI period)
        $totalPenPoints = $this->penelitian()
            ->where('status', 'Approved')
            ->whereBetween('tahun', [$kpiYearStart, $kpiYearEnd])
            ->sum('awarded_points');

        // C. Scopus points within KPI period (year matches the KPI period)
        $totalScopusPoints = $this->scopusPublications()
            ->whereBetween('year', [$kpiYearStart, $kpiYearEnd])
            ->sum('awarded_points');

        // D. Google Scholar points (publications within KPI period, excluding cross-indexed Scopus to avoid double counting)
        $scopusTitles = $this->scopusPublications()
            ->pluck('title')
            ->map(fn($t) => preg_replace('/[^a-z0-9]/', '', strtolower($t)))
            ->filter()
            ->toArray();

        $scholarPoints = 0;
        $scholarPubs = $this->publications()
            ->whereBetween('year', [$kpiYearStart, $kpiYearEnd])
            ->get();

        foreach ($scholarPubs as $pub) {
            $normTitle = preg_replace('/[^a-z0-9]/', '', strtolower($pub->title));
            if (!in_array($normTitle, $scopusTitles)) {
                $citations = (int)($pub->citations ?? 0);
                $scholarPoints += round(0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25);
            }
        }

        $this->update(['total_kpi_points' => round($totalDocPoints + $totalPenPoints + $totalScopusPoints + $scholarPoints)]);
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


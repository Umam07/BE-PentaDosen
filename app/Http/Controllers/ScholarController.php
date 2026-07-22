<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use App\Models\ScholarData;
use App\Models\ScholarPublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ScholarController extends Controller
{
    private function cleanScholarId(?string $id): ?string
    {
        if (!$id) return null;
        $id = trim($id);
        if (preg_match('/user=([a-zA-Z0-9_-]+)/', $id, $matches)) {
            return $matches[1];
        }
        $clean = preg_replace('/[&?].*$/', '', $id);
        return trim($clean);
    }

    public function sync(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $scholarId = $this->cleanScholarId($user->scholar_id);

        if (!$scholarId) {
            return response()->json(['error' => 'Scholar ID not found'], 400);
        }

        $apiKey = config('services.serpapi.key');
        if (!$apiKey) {
            return response()->json(['error' => 'SerpApi Key not configured'], 500);
        }

        $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
            'engine' => 'google_scholar_author',
            'author_id' => $scholarId,
            'api_key' => $apiKey
        ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to fetch data from SerpApi'], 500);
        }

        $data = $response->json();
        
        $totalCitations = 0;
        $hIndex = 0;
        $i10Index = 0;

        if (isset($data['cited_by']['table'])) {
            foreach ($data['cited_by']['table'] as $row) {
                if (isset($row['citations']['all'])) {
                    $totalCitations = $row['citations']['all'];
                }
                if (isset($row['h_index']['all'])) {
                    $hIndex = $row['h_index']['all'];
                }
                if (isset($row['i10_index']['all'])) {
                    $i10Index = $row['i10_index']['all'];
                }
            }
        }

        $thumbnail = $data['author']['thumbnail'] ?? null;

        DB::transaction(function () use ($user, $totalCitations, $hIndex, $i10Index, $data, $thumbnail) {
            $scholarData = ScholarData::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'thumbnail' => $thumbnail,
                    'total_citations' => $totalCitations,
                    'h_index' => $hIndex,
                    'i10_index' => $i10Index,
                    'last_synced' => now()
                ]
            );

            // Update user avatar
            if ($thumbnail) {
                $user->update(['avatar' => $thumbnail]);
            }

            // KPI Active period
            $kpiPeriodStart = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_start', '2026-01-01'));
            $kpiPeriodEnd   = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_end', '2026-12-31'));
            $kpiPeriodLabel = \App\Models\SystemSetting::getValue('kpi_period_label', '2026');

            // Sync publications
            $publications = $data['articles'] ?? [];
            foreach ($publications as $pub) {
                $year = isset($pub['year']) && is_numeric($pub['year']) ? (int)$pub['year'] : null;
                $citations = isset($pub['cited_by']['value']) && is_numeric($pub['cited_by']['value']) ? (int)$pub['cited_by']['value'] : 0;

                ScholarPublication::updateOrCreate(
                    ['user_id' => $user->id, 'title' => $pub['title']],
                    [
                        'authors' => $pub['authors'] ?? '',
                        'journal' => $pub['publication'] ?? '',
                        'year' => $year,
                        'citations' => $citations,
                    ]
                );

                // Calculate GS Points:
                // GS DOCUMENT: 0.5
                // GS DOCUMENT TERSITASI: 0.5 (if citations > 0)
                // GS CITATION PER DOCUMENT NUMBER (CUT OFF = 500): 0.25 (citations * 0.25, max 500 citations)
                $awardedPoints = round(0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25);

                // Add to Document table automatically if within KPI period
                if ($year) {
                    $publishedAt = \Carbon\Carbon::createFromDate($year, 1, 1);
                    $isKpi = $publishedAt->between($kpiPeriodStart, $kpiPeriodEnd);

                    if ($isKpi) {
                        $doc = \App\Models\Document::where('user_id', $user->id)
                            ->where('title', $pub['title'])
                            ->first();

                        if ($doc) {
                            $doc->update([
                                'category' => 'Google Scholar',
                                'awarded_points' => $awardedPoints,
                            ]);
                        } else {
                            $doc = \App\Models\Document::create([
                                'user_id' => $user->id,
                                'title' => $pub['title'],
                                'category' => 'Google Scholar',
                                'file_url' => '', // Cannot be null, use empty string
                                'published_at' => $publishedAt->format('Y-m-d'),
                                'is_kpi_counted' => true,
                                'accreditation_period' => $kpiPeriodLabel,
                                'status' => 'Approved',
                                'awarded_points' => $awardedPoints,
                            ]);
                        }
                    }
                }
            }

            // Recalculate total kpi points
            $user->recalculateKpiPoints();
        });
        
        \App\Models\ActivityLog::log($user->id, 'Sync Scholar', 'User melakukan sinkronisasi data Google Scholar');

        if (Cache::supportsTags()) {
            Cache::tags(['stats', 'leaderboard', 'lecturers', 'admin_documents', 'documents'])->flush();
        } else {
            Cache::flush();
        }

        return response()->json(['success' => true, 'message' => 'Data synced successfully']);
    }

    public function updateScholarId(Request $request, $id)
    {
        $rawScholarId = $request->scholar_id;
        $cleanId = $this->cleanScholarId($rawScholarId);
        $request->merge(['scholar_id' => $cleanId]);

        $request->validate([
            'scholar_id' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/']
        ]);
        
        $user = User::findOrFail($id);
        $newScholarId = $request->scholar_id;

        DB::transaction(function () use ($user, $newScholarId, $request) {
            $user->update([
                'scholar_id' => $newScholarId,
                'avatar' => $request->avatar ?? ($newScholarId ? $user->avatar : null)
            ]);

            // If ID is being deleted (set to null), clear associated cached data
            if (is_null($newScholarId)) {
                ScholarData::where('user_id', $user->id)->delete();
                ScholarPublication::where('user_id', $user->id)->delete();
                
                // Also remove auto-synced documents from Scholar to keep points accurate
                \App\Models\Document::where('user_id', $user->id)
                    ->where('category', 'Google Scholar')
                    ->where('file_url', '')
                    ->delete();
                
                // Recalculate total kpi points
                $user->recalculateKpiPoints();
            }
        });

        return response()->json(['success' => true]);
    }

    public function checkId($scholar_id)
    {
        $scholar_id = $this->cleanScholarId($scholar_id);
        if (!$scholar_id) {
            return response()->json(['error' => 'Invalid Google Scholar ID format'], 400);
        }

        $cached = Cache::remember("scholar_check_{$scholar_id}", 86400, function() use ($scholar_id) {
            $apiKey = config('services.serpapi.key');
            if (!$apiKey) {
                return [
                    'status' => 500,
                    'data' => ['error' => 'SerpApi Key not configured']
                ];
            }

            $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'engine' => 'google_scholar_author',
                'author_id' => $scholar_id,
                'api_key' => $apiKey
            ]);

            if ($response->failed()) {
                return [
                    'status' => 500,
                    'data' => ['error' => 'Failed to fetch data from SerpApi']
                ];
            }

            $data = $response->json();
            
            if (isset($data['error'])) {
                 return [
                     'status' => 404,
                     'data' => ['error' => 'Author not found']
                 ];
            }

            $author = $data['author'] ?? null;
            if (!$author) {
                return [
                    'status' => 404,
                    'data' => ['error' => 'Author information not found for this ID']
                ];
            }

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'name' => $author['name'] ?? 'Unknown',
                    'affiliations' => $author['affiliations'] ?? 'Unknown Affiliation',
                    'thumbnail' => $author['thumbnail'] ?? null
                ]
            ];
        });

        return response()->json($cached['data'], $cached['status']);
    }
}

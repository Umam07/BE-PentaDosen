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
    public function sync(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if (!$user->scholar_id) {
            return response()->json(['error' => 'Scholar ID not found'], 400);
        }

        $apiKey = config('services.serpapi.key');
        if (!$apiKey) {
            return response()->json(['error' => 'SerpApi Key not configured'], 500);
        }

        $response = Http::get('https://serpapi.com/search.json', [
            'engine' => 'google_scholar_author',
            'author_id' => $user->scholar_id,
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

            // Get weight for Jurnal Nasional as default for Scholar
            $weight = \App\Models\PointWeight::where('category', 'Jurnal Nasional')->first();
            $awardedPoints = $weight ? $weight->weight_value : 20;

            // KPI Active period
            $kpiPeriodStart = \Carbon\Carbon::parse('2025-01-01');
            $kpiPeriodEnd   = \Carbon\Carbon::parse('2027-12-31');
            $kpiPeriodLabel = '2025-2027';

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

                // Add to Document table automatically if within KPI period
                if ($year) {
                    $publishedAt = \Carbon\Carbon::createFromDate($year, 1, 1);
                    $isKpi = $publishedAt->between($kpiPeriodStart, $kpiPeriodEnd);

                    if ($isKpi) {
                        $doc = \App\Models\Document::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'title' => $pub['title']
                            ],
                            [
                                'category' => 'Jurnal Nasional',
                                'file_url' => '', // Cannot be null, use empty string
                                'published_at' => $publishedAt->format('Y-m-d'),
                                'is_kpi_counted' => true,
                                'accreditation_period' => $kpiPeriodLabel,
                                'status' => 'Approved',
                                'awarded_points' => $awardedPoints,
                            ]
                        );

                        if ($doc->wasRecentlyCreated && $awardedPoints > 0) {
                            $user->increment('total_kpi_points', $awardedPoints);
                        }
                    }
                }
            }
        });

        return response()->json(['success' => true, 'message' => 'Data synced successfully']);
    }

    public function updateScholarId(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update(['scholar_id' => $request->scholar_id]);

        return response()->json(['success' => true]);
    }

    public function checkId($scholar_id)
    {
        return Cache::remember("scholar_check_{$scholar_id}", 86400, function() use ($scholar_id) {
            $apiKey = config('services.serpapi.key');
            if (!$apiKey) {
                return response()->json(['error' => 'SerpApi Key not configured'], 500);
            }

            $response = Http::get('https://serpapi.com/search.json', [
                'engine' => 'google_scholar_author',
                'author_id' => $scholar_id,
                'api_key' => $apiKey
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Failed to fetch data from SerpApi'], 500);
            }

            $data = $response->json();
            
            if (isset($data['error'])) {
                 return response()->json(['error' => 'Author not found'], 404);
            }

            $author = $data['author'] ?? null;
            if (!$author) {
                return response()->json(['error' => 'Author information not found for this ID'], 404);
            }

            return response()->json([
                'success' => true,
                'name' => $author['name'] ?? 'Unknown',
                'affiliations' => $author['affiliations'] ?? 'Unknown Affiliation',
                'thumbnail' => $author['thumbnail'] ?? null
            ]);
        });
    }
}

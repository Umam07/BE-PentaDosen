<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ScopusData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ScopusController extends Controller
{
    public function sync(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if (!$user->scopus_id) {
            return response()->json(['error' => 'Scopus ID not found'], 400);
        }

        $apiKey = config('services.scopus.key');
        if (!$apiKey) {
            return response()->json(['error' => 'Scopus API Key not configured'], 500);
        }

        $entries = [];
        $totalResults = 0;
        $start = 0;
        $count = 25; // max allowed per request for standard queries
        
        do {
            $response = Http::withHeaders([
                'X-ELS-APIKey' => $apiKey,
                'Accept' => 'application/json'
            ])->timeout(15)->get("https://api.elsevier.com/content/search/scopus", [
                'query' => 'AU-ID(' . $user->scopus_id . ')',
                'count' => $count,
                'start' => $start
            ]);

            if ($response->failed()) {
                if ($start === 0) {
                    return response()->json(['error' => 'Failed to fetch data from Scopus. Check API Key or authorization.', 'details' => $response->json()], 500);
                }
                break; // Stop fetching if subsequent pages fail
            }

            $data = $response->json();
            $batchEntries = $data['search-results']['entry'] ?? [];
            if (empty($batchEntries) || (count($batchEntries) === 1 && isset($batchEntries[0]['error']))) {
                break;
            }
            
            $entries = array_merge($entries, $batchEntries);
            $totalResults = (int)($data['search-results']['opensearch:totalResults'] ?? 0);
            
            $start += $count;
        } while ($start < $totalResults && $start < 500); // cap at 500 to prevent too many API requests

        $documentCount = $totalResults;
        $citationCount = 0;
        $citationsList = [];

        foreach ($entries as $entry) {
            $citedBy = (int)($entry['citedby-count'] ?? 0);
            $citationCount += $citedBy;
            $citationsList[] = $citedBy;
        }

        // Calculate H-Index manually from fetched papers
        rsort($citationsList);
        $hIndex = 0;
        foreach ($citationsList as $i => $c) {
            if ($c >= ($i + 1)) {
                $hIndex = $i + 1;
            } else {
                break;
            }
        }

        DB::transaction(function () use ($user, $documentCount, $citationCount, $hIndex, $entries) {
            ScopusData::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'document_count' => $documentCount,
                    'total_citations' => $citationCount,
                    'h_index' => $hIndex,
                    'last_synced' => now()
                ]
            );

            // Clear old publications
            $user->scopusPublications()->delete();

            // Get weight for Jurnal Internasional as default for Scopus
            $weight = \App\Models\PointWeight::where('category', 'Jurnal Internasional')->first();
            $awardedPoints = $weight ? $weight->weight_value : 40;

            // KPI Active period
            $kpiPeriodStart = \Carbon\Carbon::parse('2025-01-01');
            $kpiPeriodEnd   = \Carbon\Carbon::parse('2027-12-31');
            $kpiPeriodLabel = '2025-2027';

            // Insert new publications
            $publicationsToInsert = [];
            foreach ($entries as $entry) {
                if (!isset($entry['dc:title'])) {
                    continue;
                }
                
                $year = null;
                if (isset($entry['prism:coverDate'])) {
                    $year = substr($entry['prism:coverDate'], 0, 4);
                }

                $publicationsToInsert[] = [
                    'user_id' => $user->id,
                    'title' => $entry['dc:title'],
                    'authors' => $entry['dc:creator'] ?? null,
                    'journal' => $entry['prism:publicationName'] ?? null,
                    'year' => $year,
                    'citations' => (int)($entry['citedby-count'] ?? 0),
                    'doi' => $entry['prism:doi'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Add to Document table automatically if within KPI period
                if ($year) {
                    $publishedAt = \Carbon\Carbon::createFromDate($year, 1, 1);
                    $isKpi = $publishedAt->between($kpiPeriodStart, $kpiPeriodEnd);

                    if ($isKpi) {
                        $doc = \App\Models\Document::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'title' => $entry['dc:title']
                            ],
                            [
                                'category' => 'Jurnal Internasional',
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
            
            if (!empty($publicationsToInsert)) {
                $user->scopusPublications()->insert($publicationsToInsert);
            }
        });

        \App\Models\ActivityLog::log($user->id, 'Sync Scopus', 'User melakukan sinkronisasi data Scopus');

        return response()->json(['success' => true, 'message' => 'Scopus Data synced successfully']);
    }

    public function updateScopusId(Request $request, $id)
    {
        $request->validate([
            'scopus_id' => 'nullable|numeric'
        ]);
        
        $user = User::findOrFail($id);
        $newScopusId = $request->scopus_id;

        DB::transaction(function () use ($user, $newScopusId) {
            $user->update(['scopus_id' => $newScopusId]);

            // If ID is being deleted (set to null), clear associated cached data
            if (is_null($newScopusId)) {
                \App\Models\ScopusData::where('user_id', $user->id)->delete();
                \App\Models\ScopusPublication::where('user_id', $user->id)->delete();
                
                // Also remove auto-synced documents from Scopus to keep points accurate
                \App\Models\Document::where('user_id', $user->id)
                    ->where('category', 'Jurnal Internasional')
                    ->where('file_url', '')
                    ->delete();
                
                // Recalculate total kpi points
                $totalPoints = \App\Models\Document::where('user_id', $user->id)
                    ->where('status', 'Approved')
                    ->sum('awarded_points');
                $user->update(['total_kpi_points' => $totalPoints]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function checkId($scopus_id)
    {
        $cached = Cache::remember("scopus_check_{$scopus_id}", 86400, function() use ($scopus_id) {
            $apiKey = config('services.scopus.key');
            if (!$apiKey) {
                return [
                    'status' => 500,
                    'data' => ['error' => 'Scopus API Key not configured']
                ];
            }

            // Test the author ID validity using Search API bypassing restrictions
            $response = Http::withHeaders([
                'X-ELS-APIKey' => $apiKey,
                'Accept' => 'application/json'
            ])->timeout(15)->get("https://api.elsevier.com/content/search/scopus", [
                'query' => 'AU-ID(' . $scopus_id . ')',
                'count' => 1
            ]);

            if ($response->failed()) {
                return [
                    'status' => 500,
                    'data' => ['error' => 'Failed to connect to Scopus']
                ];
            }

            $data = $response->json();
            
            // If no documents found, we can't verify the author easily through standard free API
            if (empty($data['search-results']['entry']) || !isset($data['search-results']['entry'][0]['dc:title'])) {
                return [
                    'status' => 404,
                    'data' => ['error' => 'Author/Documents not found. Please ensure the author has registered documents.']
                ];
            }

            $authorInfo = $data['search-results']['entry'][0];
            $name = $authorInfo['dc:creator'] ?? 'Scopus Author ID: ' . $scopus_id;
            $affiliation = $authorInfo['affiliation'][0]['affilname'] ?? 'Pencarian Scopus';

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'name' => trim($name),
                    'affiliations' => is_string($affiliation) ? $affiliation : 'Scopus Author'
                ]
            ];
        });

        return response()->json($cached['data'], $cached['status']);
    }
}

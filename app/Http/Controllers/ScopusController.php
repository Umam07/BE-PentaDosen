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
            ])->get("https://api.elsevier.com/content/search/scopus", [
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
        } while ($start < $totalResults && $start < 100); // cap at 100 to prevent too many API requests

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

        return response()->json(['success' => true, 'message' => 'Scopus Data synced successfully']);
    }

    public function updateScopusId(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update(['scopus_id' => $request->scopus_id]);

        return response()->json(['success' => true]);
    }

    public function checkId($scopus_id)
    {
        return Cache::remember("scopus_check_{$scopus_id}", 86400, function() use ($scopus_id) {
            $apiKey = config('services.scopus.key');
            if (!$apiKey) {
                return response()->json(['error' => 'Scopus API Key not configured'], 500);
            }

            // Test the author ID validity using Search API bypassing restrictions
            $response = Http::withHeaders([
                'X-ELS-APIKey' => $apiKey,
                'Accept' => 'application/json'
            ])->get("https://api.elsevier.com/content/search/scopus", [
                'query' => 'AU-ID(' . $scopus_id . ')',
                'count' => 1
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Failed to connect to Scopus'], 500);
            }

            $data = $response->json();
            
            // If no documents found, we can't verify the author easily through standard free API
            if (empty($data['search-results']['entry']) || !isset($data['search-results']['entry'][0]['dc:title'])) {
                return response()->json(['error' => 'Author/Documents not found. Please ensure the author has registered documents.'], 404);
            }

            $authorInfo = $data['search-results']['entry'][0];
            $name = $authorInfo['dc:creator'] ?? 'Scopus Author ID: ' . $scopus_id;
            $affiliation = $authorInfo['affiliation'][0]['affilname'] ?? 'Pencarian Scopus';

            return response()->json([
                'success' => true,
                'name' => trim($name),
                'affiliations' => is_string($affiliation) ? $affiliation : 'Scopus Author'
            ]);
        });
    }
}

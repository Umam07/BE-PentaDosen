<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use App\Models\ScholarData;
use App\Models\ScholarPublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

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

            // Update user total KPI points (mock logic: 1 citation = 1 point)
            // Or use a more complex logic
            $user->increment('total_kpi_points', $totalCitations - ($scholarData->getOriginal('total_citations') ?? 0));

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
    }
}

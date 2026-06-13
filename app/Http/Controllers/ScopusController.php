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

        DB::transaction(function () use ($user, $documentCount, $citationCount, $hIndex, $entries, $apiKey) {
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

                // 1. Detect Journal Quartile (SJR lookup & API fallback)
                $issn = $entry['prism:issn'] ?? null;
                $eIssn = $entry['prism:eIssn'] ?? null;
                $quartile = null;

                if ($issn) {
                    $sjr = DB::table('sjr_journals')->where('issn', $issn)->first();
                    if ($sjr) {
                        $quartile = $sjr->quartile;
                    }
                }
                if (!$quartile && $eIssn) {
                    $sjr = DB::table('sjr_journals')->where('issn', $eIssn)->first();
                    if ($sjr) {
                        $quartile = $sjr->quartile;
                    }
                }

                // Fallback to Scopus Sources API
                if (!$quartile && ($issn || $eIssn)) {
                    $searchIssn = $issn ?: $eIssn;
                    try {
                        $sourceRes = Http::withHeaders([
                            'X-ELS-APIKey' => $apiKey,
                            'Accept' => 'application/json'
                        ])->timeout(8)->get("https://api.elsevier.com/content/serial/title", [
                            'issn' => $searchIssn
                        ]);
                        if ($sourceRes->successful()) {
                            $sourceData = $sourceRes->json();
                            $entryMetadata = $sourceData['serial-metadata-response']['entry'][0] ?? null;
                            if ($entryMetadata && isset($entryMetadata['citeScoreYearInfoList']['citeScoreYearInfo'])) {
                                $yearInfos = $entryMetadata['citeScoreYearInfoList']['citeScoreYearInfo'];
                                $latestYearInfo = $yearInfos[0] ?? null;
                                if ($latestYearInfo && isset($latestYearInfo['citeScoreSubjectAreaList']['citeScoreSubjectArea'])) {
                                    $subjAreas = $latestYearInfo['citeScoreSubjectAreaList']['citeScoreSubjectArea'];
                                    $maxPercentile = 0;
                                    foreach ($subjAreas as $sa) {
                                        $percentile = (int)($sa['percentile'] ?? 0);
                                        if ($percentile > $maxPercentile) {
                                            $maxPercentile = $percentile;
                                        }
                                    }
                                    if ($maxPercentile >= 75) {
                                        $quartile = 'Q1';
                                    } elseif ($maxPercentile >= 50) {
                                        $quartile = 'Q2';
                                    } elseif ($maxPercentile >= 25) {
                                        $quartile = 'Q3';
                                    } elseif ($maxPercentile > 0) {
                                        $quartile = 'Q4';
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        // Silently ignore API lookup failures
                    }
                }

                if (!$quartile) {
                    $quartile = 'None';
                }

                // 2. Detect Author Role (Single, First, Member Author, Hyperauthor)
                $authorList = $entry['author'] ?? [];
                $totalAuthors = count($authorList);
                $authorRole = 'Member Author';
                $isHyperauthor = false;

                if ($totalAuthors > 16) {
                    $isHyperauthor = true;
                }

                $userIndex = -1;
                foreach ($authorList as $idx => $auth) {
                    $authId = $auth['authid'] ?? null;
                    if ($authId && (string)$authId === (string)$user->scopus_id) {
                        $userIndex = $idx;
                        break;
                    }
                }

                if ($totalAuthors === 1) {
                    $authorRole = 'Single Author';
                } elseif ($userIndex === 0) {
                    $authorRole = 'First Author';
                } elseif ($userIndex > 0) {
                    $authorRole = 'Member Author';
                }

                // Fallback to CrossRef API if COMPLETE view didn't return authors or returned empty
                if ($totalAuthors === 0 && isset($entry['prism:doi']) && !empty($entry['prism:doi'])) {
                    try {
                        $crossRefRes = Http::timeout(5)->get("https://api.crossref.org/works/" . $entry['prism:doi']);
                        if ($crossRefRes->successful()) {
                            $crData = $crossRefRes->json();
                            $crAuthors = $crData['message']['author'] ?? [];
                            $totalAuthors = count($crAuthors);
                            if ($totalAuthors > 16) {
                                $isHyperauthor = true;
                            }

                            // Normalizing user name for matching
                            $normUser = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $user->name));
                            $titles = ['s.kom', 'm.kom', 'dr.', 'dr', 'drg.', 'drg', 's.si', 'm.kes', 's.psi', 'm.psi', 's.h.', 'm.h.', 'prof.'];
                            foreach ($titles as $t) {
                                $normUser = str_replace(strtolower($t), '', $normUser);
                            }
                            $userWords = array_filter(explode(' ', trim($normUser)));

                            $userIndex = -1;
                            foreach ($crAuthors as $idx => $cra) {
                                $given = strtolower($cra['given'] ?? '');
                                $family = strtolower($cra['family'] ?? '');
                                $fullName = $given . ' ' . $family;

                                $matchCount = 0;
                                foreach ($userWords as $w) {
                                    if (str_contains($fullName, $w)) {
                                        $matchCount++;
                                    }
                                }
                                if ($matchCount >= 2 || (count($userWords) === 1 && $matchCount === 1)) {
                                    $userIndex = $idx;
                                    break;
                                }
                            }

                            if ($totalAuthors === 1) {
                                $authorRole = 'Single Author';
                            } elseif ($userIndex === 0) {
                                $authorRole = 'First Author';
                            } elseif ($userIndex > 0) {
                                $authorRole = 'Member Author';
                            }
                        }
                    } catch (\Exception $e) {
                        // Silently ignore CrossRef failures
                    }
                }

                // If role is still Member Author (or we couldn't fetch authors), try matching dc:creator
                if ($authorRole === 'Member Author' && isset($entry['dc:creator'])) {
                    if ($this->matchCreatorName($user->name, $entry['dc:creator'])) {
                        $authorRole = 'First Author';
                    }
                }

                // 3. Determine if Article or Non-Article
                $subtype = $entry['subtype'] ?? null;
                $subtypeDescription = $entry['subtypeDescription'] ?? null;
                
                $isArticle = true;
                if ($subtype && strtolower($subtype) !== 'ar') {
                    $isArticle = false;
                } elseif ($subtypeDescription && strtolower($subtypeDescription) !== 'article') {
                    $isArticle = false;
                }

                // 4. Map to PointWeight and calculate points
                $pointCategory = 'Jurnal Internasional'; // default fallback
                if ($isArticle) {
                    if ($authorRole === 'Single Author') {
                        $pointCategory = 'Scopus Article (Single Author)';
                    } elseif ($isHyperauthor) {
                        if ($authorRole === 'First Author') {
                            $pointCategory = 'Scopus Article Hyperauthor (First Author)';
                        } else {
                            $pointCategory = 'Scopus Article Hyperauthor (Member Author)';
                        }
                    } else {
                        $q = in_array($quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $quartile : 'Q4';
                        if ($authorRole === 'First Author') {
                            $pointCategory = "Scopus Article {$q} (First Author)";
                        } else {
                            $pointCategory = "Scopus Article {$q} (Member Author)";
                        }
                    }
                } else {
                    if ($authorRole === 'Single Author') {
                        $pointCategory = 'Scopus Non Article (Single Author)';
                    } elseif ($authorRole === 'First Author') {
                        $pointCategory = 'Scopus Non Article (First Author)';
                    } else {
                        $pointCategory = 'Scopus Non Article (Member Author)';
                    }
                }

                $pointWeightObj = \App\Models\PointWeight::where('category', $pointCategory)->first();
                if (!$pointWeightObj) {
                    if (!$isArticle) {
                        if ($authorRole === 'Single Author') $basePoints = 30;
                        elseif ($authorRole === 'First Author') $basePoints = 18;
                        else $basePoints = 12;
                    } else {
                        if ($authorRole === 'Single Author') $basePoints = 40;
                        elseif ($isHyperauthor) {
                            $basePoints = ($authorRole === 'First Author') ? 24 : 1;
                        } else {
                            $q = in_array($quartile, ['Q1', 'Q2', 'Q3', 'Q4']) ? $quartile : 'Q4';
                            if ($authorRole === 'First Author') {
                                $basePoints = ($q === 'Q1') ? 24 : (($q === 'Q2') ? 22 : (($q === 'Q3') ? 20 : 18));
                            } else {
                                $basePoints = ($q === 'Q1') ? 16 : (($q === 'Q2') ? 14 : (($q === 'Q3') ? 12 : 10));
                            }
                        }
                    }
                } else {
                    $basePoints = $pointWeightObj->weight_value;
                }

                $citations = (int)($entry['citedby-count'] ?? 0);
                $citationPoints = $totalAuthors > 0 ? ($citations / $totalAuthors) : 0;
                $citationBonus = $citations > 0 ? 5 : 0;
                $awardedPoints = $basePoints + $citationPoints + $citationBonus;

                $publicationsToInsert[] = [
                    'user_id' => $user->id,
                    'title' => $entry['dc:title'],
                    'authors' => $entry['dc:creator'] ?? null,
                    'journal' => $entry['prism:publicationName'] ?? null,
                    'year' => $year,
                    'citations' => $citations,
                    'doi' => $entry['prism:doi'] ?? null,
                    'quartile' => $quartile === 'None' ? null : $quartile,
                    'author_role' => $authorRole,
                    'is_hyperauthor' => $isHyperauthor,
                    'awarded_points' => $awardedPoints,
                    'subtype' => $subtype ?: ($subtypeDescription ?: null),
                    'total_authors' => $totalAuthors,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Add to Document table automatically if within KPI period
                if ($year) {
                    $publishedAt = \Carbon\Carbon::createFromDate($year, 1, 1);
                    $isKpi = $publishedAt->between($kpiPeriodStart, $kpiPeriodEnd);

                    if ($isKpi) {
                        $doc = \App\Models\Document::where('user_id', $user->id)
                            ->where('title', $entry['dc:title'])
                            ->first();

                        if ($doc) {
                            $pointDiff = $awardedPoints - $doc->awarded_points;
                            $doc->update([
                                'awarded_points' => $awardedPoints,
                                'quartile' => $quartile === 'None' ? null : $quartile,
                                'author_role' => $authorRole,
                                'is_hyperauthor' => $isHyperauthor,
                            ]);
                            if ($pointDiff != 0) {
                                $user->increment('total_kpi_points', $pointDiff);
                            }
                        } else {
                            $doc = \App\Models\Document::create([
                                'user_id' => $user->id,
                                'title' => $entry['dc:title'],
                                'category' => 'Jurnal Internasional',
                                'file_url' => '',
                                'published_at' => $publishedAt->format('Y-m-d'),
                                'is_kpi_counted' => true,
                                'accreditation_period' => $kpiPeriodLabel,
                                'status' => 'Approved',
                                'awarded_points' => $awardedPoints,
                                'quartile' => $quartile === 'None' ? null : $quartile,
                                'author_role' => $authorRole,
                                'is_hyperauthor' => $isHyperauthor,
                            ]);
                            if ($awardedPoints > 0) {
                                $user->increment('total_kpi_points', $awardedPoints);
                            }
                        }
                    }
                }
            }
            
            if (!empty($publicationsToInsert)) {
                $user->scopusPublications()->insert($publicationsToInsert);
            }
        });

        \App\Models\ActivityLog::log($user->id, 'Sync Scopus', 'User melakukan sinkronisasi data Scopus');

        if (Cache::supportsTags()) {
            Cache::tags(['stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

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
                $totalDocPoints = \App\Models\Document::where('user_id', $user->id)
                    ->where('status', 'Approved')
                    ->sum('awarded_points');
                $totalPenPoints = \App\Models\Penelitian::where('user_id', $user->id)
                    ->where('status', 'Approved')
                    ->sum('awarded_points');

                $user->update(['total_kpi_points' => $totalDocPoints + $totalPenPoints]);
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

    private function matchAuthorName($userFullName, $authorGiven, $authorFamily)
    {
        $userNorm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $userFullName));
        $titles = ['s.kom', 'm.kom', 'dr.', 'dr', 'drg.', 'drg', 's.si', 'm.kes', 's.si.', 's.si,', 's.psi', 'm.psi', 's.h.', 'm.h.', 'prof.', 's.s.'];
        foreach ($titles as $t) {
            $userNorm = str_replace(strtolower($t), '', $userNorm);
        }
        $userWords = array_filter(explode(' ', trim($userNorm)));

        $givenNorm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $authorGiven));
        $familyNorm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $authorFamily));
        $authorWords = array_merge(explode(' ', $givenNorm), explode(' ', $familyNorm));
        $authorWords = array_filter($authorWords);

        $matches = 0;
        foreach ($userWords as $uw) {
            foreach ($authorWords as $aw) {
                if ($uw === $aw || (strlen($aw) === 1 && str_starts_with($uw, $aw)) || (strlen($uw) === 1 && str_starts_with($aw, $uw))) {
                    $matches++;
                    break;
                }
            }
        }

        return $matches >= 2 || (count($userWords) === 1 && $matches === 1);
    }

    private function matchCreatorName($userFullName, $creatorName)
    {
        if (!$creatorName) return false;
        
        $userNorm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $userFullName));
        $titles = ['s.kom', 'm.kom', 'dr.', 'dr', 'drg.', 'drg', 's.si', 'm.kes', 's.si.', 's.si,', 's.psi', 'm.psi', 's.h.', 'm.h.', 'prof.', 's.s.'];
        foreach ($titles as $t) {
            $userNorm = str_replace(strtolower($t), '', $userNorm);
        }
        $userWords = array_filter(explode(' ', trim($userNorm)));

        $creatorNorm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $creatorName));
        $creatorWords = array_filter(explode(' ', $creatorNorm));

        $matches = 0;
        foreach ($userWords as $uw) {
            foreach ($creatorWords as $cw) {
                if ($uw === $cw || (strlen($cw) === 1 && str_starts_with($uw, $cw)) || (strlen($uw) === 1 && str_starts_with($cw, $uw))) {
                    $matches++;
                    break;
                }
            }
        }
        return $matches >= 2 || (count($userWords) === 1 && $matches === 1);
    }
}

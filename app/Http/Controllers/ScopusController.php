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

        // Fetch existing publications to preserve corresponding status before deletion
        $existingPubs = \App\Models\ScopusPublication::where('user_id', $user->id)
            ->get()
            ->keyBy(function ($p) {
                return strtolower(trim($p->title));
            });

        DB::transaction(function () use ($user, $documentCount, $citationCount, $hIndex, $entries, $apiKey, $existingPubs) {
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

            // Load point weights dynamically from database
            $weights = \App\Models\PointWeight::pluck('weight_value', 'category')->toArray();

            // KPI Active period
            $kpiPeriodStart = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_start', '2026-01-01'));
            $kpiPeriodEnd   = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_end', '2026-12-31'));
            $kpiPeriodLabel = \App\Models\SystemSetting::getValue('kpi_period_label', '2026');

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

                // 1. Detect Journal Quartile (SJR lookup with normalized ISSN)
                $issn  = $entry['prism:issn']  ?? null;
                $eIssn = $entry['prism:eIssn'] ?? null;
                $quartile = null;

                // Normalize ISSNs: try both with-dash (xxxx-xxxx) and without-dash (xxxxxxxx) forms
                $issnVariants = [];
                foreach ([$issn, $eIssn] as $raw) {
                    if (!$raw) continue;
                    $clean = preg_replace('/[^0-9Xx]/', '', $raw);
                    $issnVariants[] = strtoupper($clean);                          // no dash
                    if (strlen($clean) === 8) {
                        $issnVariants[] = substr($clean, 0, 4) . '-' . substr($clean, 4); // with dash
                    }
                }
                $issnVariants = array_unique(array_filter($issnVariants));

                if (!empty($issnVariants)) {
                    $sjr = DB::table('sjr_journals')
                        ->whereIn(DB::raw('UPPER(issn)'), $issnVariants)
                        ->first();
                    if ($sjr) {
                        $quartile = $sjr->quartile;
                    }
                }

                // Fallback to Scopus Sources API (CiteScore) if SJR not found locally
                if (!$quartile && !empty($issnVariants)) {
                    $searchIssn = preg_replace('/-/', '', $issn ?: $eIssn); // use no-dash form
                    try {
                        $sourceRes = Http::withHeaders([
                            'X-ELS-APIKey' => $apiKey,
                            'Accept' => 'application/json'
                        ])->timeout(8)->get("https://api.elsevier.com/content/serial/title", [
                            'issn'  => $searchIssn,
                            'view'  => 'CITESCORE',
                            'field' => 'citeScoreYearInfoList',
                        ]);
                        if ($sourceRes->successful()) {
                            $sourceData = $sourceRes->json();
                            $entryMetadata = $sourceData['serial-metadata-response']['entry'][0] ?? null;
                            if ($entryMetadata && isset($entryMetadata['citeScoreYearInfoList']['citeScoreYearInfo'])) {
                                $yearInfos = $entryMetadata['citeScoreYearInfoList']['citeScoreYearInfo'];
                                
                                if (isset($yearInfos['citeScoreInformationList'])) {
                                    $yearInfos = [$yearInfos];
                                }

                                $maxPercentile = 0;
                                foreach ($yearInfos as $yearInfo) {
                                    if (isset($yearInfo['citeScoreInformationList'])) {
                                        $infoListWrapper = $yearInfo['citeScoreInformationList'];
                                        if (isset($infoListWrapper['citeScoreInfo'])) {
                                            $infoListWrapper = [$infoListWrapper];
                                        }
                                        
                                        foreach ($infoListWrapper as $wrapper) {
                                            $citeScoreInfo = $wrapper['citeScoreInfo'] ?? [];
                                            if (isset($citeScoreInfo['citeScoreSubjectRank'])) {
                                                $citeScoreInfo = [$citeScoreInfo];
                                            }
                                            
                                            foreach ($citeScoreInfo as $info) {
                                                $ranks = $info['citeScoreSubjectRank'] ?? [];
                                                if (isset($ranks['percentile'])) {
                                                    $ranks = [$ranks];
                                                }
                                                
                                                foreach ($ranks as $r) {
                                                    $percentile = (int)($r['percentile'] ?? 0);
                                                    if ($percentile > $maxPercentile) {
                                                        $maxPercentile = $percentile;
                                                    }
                                                }
                                            }
                                        }
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
                    } catch (\Exception $e) {
                        // Silently ignore API lookup failures
                    }
                }

                if (!$quartile) {
                    $quartile = 'None';
                }

                // 2. Detect Author Role (Single, First, Member Author, Hyperauthor)
                //    Primary: use Scopus author list (authid matching)
                $authorList   = $entry['author'] ?? [];
                $totalAuthors = count($authorList);
                $authorRole   = 'Member Author';
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

                // Fallback 1: CrossRef API (if Scopus didn't return any authors)
                if ($totalAuthors === 0 && isset($entry['prism:doi']) && !empty($entry['prism:doi'])) {
                    try {
                        $crossRefRes = Http::timeout(5)->get("https://api.crossref.org/works/" . $entry['prism:doi']);
                        if ($crossRefRes->successful()) {
                            $crData    = $crossRefRes->json();
                            $crAuthors = $crData['message']['author'] ?? [];
                            $totalAuthors = count($crAuthors);
                            if ($totalAuthors > 16) {
                                $isHyperauthor = true;
                            }

                            $userIndex = $this->matchAuthorInList($user->name, $crAuthors, 'crossref');

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

                // Fallback 2: OpenAlex API (free, comprehensive author data — best match for SINTA)
                if ($totalAuthors === 0) {
                    $doi   = $entry['prism:doi'] ?? null;
                    $title = $entry['dc:title']   ?? null;
                    try {
                        $oaUrl    = $doi
                            ? "https://api.openalex.org/works/https://doi.org/{$doi}"
                            : "https://api.openalex.org/works?filter=title.search:" . urlencode($title) . "&per-page=1";
                        $oaRes = Http::timeout(8)->withHeaders(['User-Agent' => 'PentaDosen/1.0 (mailto:admin@pentadosen.id)'])->get($oaUrl);
                        if ($oaRes->successful()) {
                            $oaData    = $oaRes->json();
                            $oaWork    = $doi ? $oaData : ($oaData['results'][0] ?? null);
                            $oaAuthors = $oaWork['authorships'] ?? [];
                            $totalAuthors = count($oaAuthors);
                            if ($totalAuthors > 16) {
                                $isHyperauthor = true;
                            }

                            $userIndex = $this->matchAuthorInList($user->name, $oaAuthors, 'openalex');

                            if ($totalAuthors === 1) {
                                $authorRole = 'Single Author';
                            } elseif ($userIndex === 0) {
                                $authorRole = 'First Author';
                            } elseif ($userIndex > 0) {
                                $authorRole = 'Member Author';
                            }
                        }
                    } catch (\Exception $e) {
                        // Silently ignore OpenAlex failures
                    }
                }

                // Fallback 3: dc:creator is always the first/corresponding author in Scopus standard API
                if ($totalAuthors === 0 && isset($entry['dc:creator'])) {
                    $totalAuthors = 1; // At minimum we know at least 1 author
                    if ($this->matchCreatorName($user->name, $entry['dc:creator'])) {
                        $authorRole = 'First Author';
                        $userIndex = 0;
                    }
                } elseif ($authorRole === 'Member Author' && $totalAuthors > 0 && isset($entry['dc:creator'])) {
                    // If we couldn't positively ID user in the list but dc:creator matches, they are first
                    if ($this->matchCreatorName($user->name, $entry['dc:creator'])) {
                        $authorRole = 'First Author';
                        $userIndex = 0;
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

                // Determine 1-based author order
                $authorOrder = null;
                if ($userIndex !== -1) {
                    $authorOrder = $userIndex + 1;
                } elseif ($authorRole === 'First Author' || $authorRole === 'Single Author') {
                    $authorOrder = 1;
                } else {
                    $authorOrder = 2; // default member order
                }

                // 4. Preserve or default corresponding status
                $normTitle = strtolower(trim($entry['dc:title']));
                $existing = $existingPubs[$normTitle] ?? null;

                if ($existing) {
                    $isCorresponding = (bool)$existing->is_corresponding;
                    $isCorrespondingConfirmed = (bool)$existing->is_corresponding_confirmed;
                } else {
                    $isCorresponding = ($authorRole === 'First Author' || $authorRole === 'Single Author' || $authorOrder === 1);
                    $isCorrespondingConfirmed = false;
                }

                // 5. Calculate points dynamically using Model helper
                $tempPub = new \App\Models\ScopusPublication([
                    'author_role' => $authorRole,
                    'total_authors' => $totalAuthors,
                    'author_order' => $authorOrder,
                    'is_hyperauthor' => $isHyperauthor,
                    'quartile' => $quartile === 'None' ? null : $quartile,
                    'subtype' => $subtype ?: ($subtypeDescription ?: null),
                    'is_corresponding' => $isCorresponding,
                ]);
                $awardedPoints = $tempPub->calculatePoints($weights);

                $citations = (int)($entry['citedby-count'] ?? 0);

                $publicationsToInsert[] = [
                    'user_id'       => $user->id,
                    'title'         => $entry['dc:title'],
                    'authors'       => $entry['dc:creator'] ?? null,
                    'journal'       => $entry['prism:publicationName'] ?? null,
                    'year'          => $year,
                    'citations'     => $citations,
                    'doi'           => $entry['prism:doi'] ?? null,
                    'quartile'      => $quartile === 'None' ? null : $quartile,
                    'author_role'   => $authorRole,
                    'author_order'  => $authorOrder,
                    'is_corresponding' => $isCorresponding,
                    'is_corresponding_confirmed' => $isCorrespondingConfirmed,
                    'is_hyperauthor' => $isHyperauthor,
                    'awarded_points' => round($awardedPoints),
                    'subtype'       => $subtype ?: ($subtypeDescription ?: null),
                    'total_authors' => $totalAuthors,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];

            }
            
            if (!empty($publicationsToInsert)) {
                $user->scopusPublications()->insert($publicationsToInsert);
            }

            // Recalculate total kpi points
            $user->recalculateKpiPoints();
        });

        \App\Models\ActivityLog::log($user->id, 'Sync Scopus', 'User melakukan sinkronisasi data Scopus');

        if (Cache::supportsTags()) {
            Cache::tags(['stats', 'leaderboard', 'lecturers', 'admin_documents', 'documents'])->flush();
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
                $user->recalculateKpiPoints();
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

            // 1. Try Author Retrieval API first (most accurate — gets official profile name directly)
            try {
                $profileRes = Http::withHeaders([
                    'X-ELS-APIKey' => $apiKey,
                    'Accept' => 'application/json'
                ])->timeout(10)->get("https://api.elsevier.com/content/author/author_id/" . $scopus_id);

                if ($profileRes->successful()) {
                    $profileData = $profileRes->json();
                    $authorData = $profileData['author-retrieval-response'][0] ?? null;
                    if ($authorData && ($authorData['@status'] ?? '') === 'found') {
                        $profile = $authorData['author-profile'] ?? null;
                        if ($profile) {
                            $preferredName = $profile['preferred-name'] ?? null;
                            $name = '';
                            if ($preferredName) {
                                $given = $preferredName['given-name'] ?? '';
                                $surname = $preferredName['surname'] ?? '';
                                $name = trim($given . ' ' . $surname);
                                if (!$name) {
                                    $name = $preferredName['indexed-name'] ?? '';
                                }
                            }
                            if (!$name) {
                                $name = 'Scopus Author ID: ' . $scopus_id;
                            }

                            $affil = $authorData['affiliation-current'] ?? null;
                            $affiliation = null;
                            if (is_array($affil)) {
                                $affiliation = $affil['affiliation-name'] ?? null;
                            }
                            if (!$affiliation) {
                                $affiliation = 'Pencarian Scopus';
                            }

                            return [
                                'status' => 200,
                                'data' => [
                                    'success' => true,
                                    'name' => $name,
                                    'affiliations' => is_string($affiliation) ? $affiliation : 'Scopus Author'
                                ]
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fallback — Author Retrieval API may return 401 for free API keys
            }

            // 2. Fallback: Cross-publication frequency analysis
            //    Fetch multiple publications for AU-ID, then use OpenAlex/CrossRef to get full
            //    author lists. The author appearing in the most publications is the ID owner.
            $response = Http::withHeaders([
                'X-ELS-APIKey' => $apiKey,
                'Accept' => 'application/json'
            ])->timeout(15)->get("https://api.elsevier.com/content/search/scopus", [
                'query' => 'AU-ID(' . $scopus_id . ')',
                'count' => 10
            ]);

            if ($response->failed()) {
                return [
                    'status' => 500,
                    'data' => ['error' => 'Failed to connect to Scopus']
                ];
            }

            $data = $response->json();

            if (empty($data['search-results']['entry']) || !isset($data['search-results']['entry'][0]['dc:title'])) {
                return [
                    'status' => 404,
                    'data' => ['error' => 'Author/Documents not found. Please ensure the author has registered documents.']
                ];
            }

            $entries = $data['search-results']['entry'];
            $firstEntry = $entries[0];
            $affiliation = $firstEntry['affiliation'][0]['affilname'] ?? 'Pencarian Scopus';

            // Collect DOIs from the publications
            $dois = [];
            foreach ($entries as $entry) {
                $d = $entry['prism:doi'] ?? null;
                if ($d) $dois[] = $d;
            }

            // Analyse author frequency across publications
            $authorCounts = [];  // lowercase name => count
            $authorNames  = [];  // lowercase name => original casing
            $authorOaIds  = [];  // lowercase name => OpenAlex ID
            $pubsAnalysed = 0;

            foreach (array_slice($dois, 0, 5) as $doi) { // Limit to 5 API calls for speed
                $authors = [];

                // Try OpenAlex first (fast, comprehensive)
                try {
                    $oaRes = Http::timeout(6)
                        ->withHeaders(['User-Agent' => 'PentaDosen/1.0 (mailto:admin@pentadosen.id)'])
                        ->get("https://api.openalex.org/works/https://doi.org/{$doi}");
                    if ($oaRes->successful()) {
                        foreach ($oaRes->json()['authorships'] ?? [] as $a) {
                            $n = $a['author']['display_name'] ?? '';
                            $oaId = $a['author']['id'] ?? null;
                            if ($n) {
                                $authors[] = [
                                    'name' => $n,
                                    'id' => $oaId
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {}

                // Fallback to CrossRef
                if (empty($authors)) {
                    try {
                        $crRes = Http::timeout(5)->get("https://api.crossref.org/works/" . $doi);
                        if ($crRes->successful()) {
                            foreach ($crRes->json()['message']['author'] ?? [] as $a) {
                                $n = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
                                if ($n) {
                                    $authors[] = [
                                        'name' => $n,
                                        'id' => null
                                    ];
                                }
                            }
                        }
                    } catch (\Exception $e) {}
                }

                if (!empty($authors)) {
                    $pubsAnalysed++;
                    foreach ($authors as $auth) {
                        $n = $auth['name'];
                        $oaId = $auth['id'];
                        $key = strtolower(trim($n));
                        $authorCounts[$key] = ($authorCounts[$key] ?? 0) + 1;
                        if (!isset($authorNames[$key])) {
                            $authorNames[$key] = $n;
                        }
                        if ($oaId && !isset($authorOaIds[$key])) {
                            $authorOaIds[$key] = $oaId;
                        }
                    }
                }
            }

            // Determine the profile owner: author with highest frequency
            $name = null;
            $matchedOaId = null;
            if ($pubsAnalysed >= 2 && !empty($authorCounts)) {
                arsort($authorCounts);
                $topKey   = array_key_first($authorCounts);
                $topCount = $authorCounts[$topKey];
                // Only trust if the top author appears in at least 2 publications
                if ($topCount >= 2) {
                    $name = $authorNames[$topKey];
                    $matchedOaId = $authorOaIds[$topKey] ?? null;
                }
            }

            // Fallback to dc:creator if frequency analysis didn't yield a result
            if (!$name) {
                $name = $firstEntry['dc:creator'] ?? 'Scopus Author ID: ' . $scopus_id;
            }

            // Try to resolve a more accurate affiliation for the matched author name via OpenAlex
            if ($name) {
                try {
                    $resolvedAffiliation = null;

                    // 1. Direct OpenAlex ID fetch (highly accurate)
                    if ($matchedOaId) {
                        $cleanId = $matchedOaId;
                        if (str_contains($matchedOaId, 'openalex.org/')) {
                            $parts = explode('openalex.org/', $matchedOaId);
                            $cleanId = end($parts);
                        }
                        $oaAuthorRes = Http::timeout(6)
                            ->withHeaders(['User-Agent' => 'PentaDosen/1.0 (mailto:admin@pentadosen.id)'])
                            ->get("https://api.openalex.org/authors/" . $cleanId);
                        if ($oaAuthorRes->successful()) {
                            $oaAuthorData = $oaAuthorRes->json();
                            $insts = $oaAuthorData['last_known_institutions'] ?? [];
                            if (!empty($insts)) {
                                $resolvedAffiliation = $insts[0]['display_name'];
                            }
                        }
                    }

                    // 2. Strict name search fallback
                    if (!$resolvedAffiliation) {
                        $oaAuthorRes = Http::timeout(6)
                            ->withHeaders(['User-Agent' => 'PentaDosen/1.0 (mailto:admin@pentadosen.id)'])
                            ->get("https://api.openalex.org/authors", [
                                'filter' => 'display_name.search:' . $name
                            ]);
                        if ($oaAuthorRes->successful()) {
                            $oaAuthorData = $oaAuthorRes->json();
                            foreach ($oaAuthorData['results'] ?? [] as $r) {
                                $foundName = $r['display_name'] ?? '';
                                if ($this->isNameMatch($name, $foundName)) {
                                    $insts = $r['last_known_institutions'] ?? [];
                                    if (!empty($insts)) {
                                        $resolvedAffiliation = $insts[0]['display_name'];
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    if ($resolvedAffiliation) {
                        $affiliation = $resolvedAffiliation;
                    }
                } catch (\Exception $e) {
                    // Silently ignore OpenAlex lookup errors
                }
            }

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

    public function updateQuartile(Request $request, $id)
    {
        $request->validate([
            'quartile' => 'nullable|string|in:Q1,Q2,Q3,Q4,None'
        ]);

        $pub = \App\Models\ScopusPublication::findOrFail($id);
        $user = $pub->user;
        $newQuartile = $request->quartile === 'None' ? null : $request->quartile;

        DB::transaction(function () use ($pub, $user, $newQuartile) {
            $pub->quartile = $newQuartile;
            
            // Recalculate points using the model helper
            $points = $pub->calculatePoints();
            $pub->awarded_points = round($points);
            $pub->save();
            
            // Recalculate total kpi points
            $user->recalculateKpiPoints();
        });

        if (Cache::supportsTags()) {
            Cache::tags(['stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

        return response()->json(['success' => true, 'message' => 'Quartile and points updated successfully', 'publication' => $pub]);
    }

    public function updateCorresponding(Request $request, $id)
    {
        $request->validate([
            'is_corresponding' => 'required|boolean'
        ]);

        $pub = \App\Models\ScopusPublication::findOrFail($id);
        $user = $pub->user;

        DB::transaction(function () use ($pub, $user, $request) {
            $oldPoints = $pub->awarded_points;
            $pub->is_corresponding = $request->is_corresponding;
            $pub->is_corresponding_confirmed = true;
            
            // Recalculate points using the model helper
            $points = $pub->calculatePoints();
            $pub->awarded_points = round($points);
            $pub->save();

            // Recalculate total kpi points
            $user->recalculateKpiPoints();
        });

        if (Cache::supportsTags()) {
            Cache::tags(['stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

        return response()->json([
            'success' => true, 
            'message' => 'Corresponding status and points updated successfully', 
            'publication' => $pub
        ]);
    }

    /**
     * Match user name against an author list from CrossRef or OpenAlex.
     * Returns the index of the matching author, or -1 if not found.
     */
    private function matchAuthorInList(string $userName, array $authors, string $source): int
    {
        $normUser = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $userName));
        $titles = ['s.kom', 'm.kom', 'dr.', 'dr', 'drg.', 'drg', 's.si', 'm.kes', 's.si.', 's.si,', 's.psi', 'm.psi', 's.h.', 'm.h.', 'prof.', 's.s.', 'm.t.', 's.t.', 'mt', 'st'];
        foreach ($titles as $t) {
            $normUser = str_replace(strtolower($t), '', $normUser);
        }
        $userWords = array_values(array_filter(explode(' ', trim($normUser))));

        foreach ($authors as $idx => $auth) {
            if ($source === 'crossref') {
                $given  = strtolower($auth['given']  ?? '');
                $family = strtolower($auth['family'] ?? '');
                $authorName = preg_replace('/[^a-z0-9 ]/', '', $given . ' ' . $family);
            } elseif ($source === 'openalex') {
                $displayName = $auth['author']['display_name'] ?? '';
                $authorName  = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $displayName));
            } else {
                continue;
            }

            $authorWords = array_filter(explode(' ', $authorName));
            $matches = 0;
            foreach ($userWords as $uw) {
                foreach ($authorWords as $aw) {
                    if (strlen($uw) >= 2 && strlen($aw) >= 2 && ($uw === $aw || str_starts_with($aw, $uw) || str_starts_with($uw, $aw))) {
                        $matches++;
                        break;
                    } elseif ((strlen($aw) === 1 && str_starts_with($uw, $aw)) || (strlen($uw) === 1 && str_starts_with($aw, $uw))) {
                        $matches++;
                        break;
                    }
                }
            }

            if ($matches >= 2 || (count($userWords) === 1 && $matches === 1)) {
                return $idx;
            }
        }

        return -1;
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

    private function isNameMatch(string $name1, string $name2): bool
    {
        $norm1 = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $name1));
        $norm2 = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $name2));
        
        $titles = ['skom', 'mkom', 'dr', 'drg', 'ssi', 'mkes', 'spsi', 'mpsi', 'sh', 'mh', 'prof', 'ss', 'mt', 'st'];
        foreach ($titles as $t) {
            $norm1 = str_replace($t, '', $norm1);
            $norm2 = str_replace($t, '', $norm2);
        }
        
        $words1 = array_values(array_filter(explode(' ', trim($norm1))));
        $words2 = array_values(array_filter(explode(' ', trim($norm2))));
        
        if (empty($words1) || empty($words2)) {
            return false;
        }
        
        // If it's a single word search name, match must be exactly that single word (or very close)
        if (count($words1) === 1) {
            return count($words2) === 1 && $words1[0] === $words2[0];
        }
        
        // Otherwise, make sure all words in words1 exist in words2 or match initials
        foreach ($words1 as $w) {
            $matched = false;
            foreach ($words2 as $w2) {
                if ($w === $w2 || (strlen($w) === 1 && str_starts_with($w2, $w)) || (strlen($w2) === 1 && str_starts_with($w, $w2))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        
        // Also ensure word counts don't differ by too much (e.g. at most 1 extra word)
        return abs(count($words1) - count($words2)) <= 1;
    }
}

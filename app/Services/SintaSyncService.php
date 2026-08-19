<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SintaSyncService
{
    const API_URL = 'https://scrapt-sinta.vercel.app/api/dosen';
    const CACHE_KEY = 'sinta_dosen_list';
    const CACHE_TTL = 3600; // 1 hour

    /**
     * Fetch the list of lecturers from SINTA scraper API.
     *
     * @param bool $fresh
     * @return array
     */
    public function fetchSintaDosenList(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $response = Http::timeout(15)->get(self::API_URL);
                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data)) {
                        return $data;
                    }
                }
                Log::warning('SintaSyncService: Failed to fetch data from SINTA API. Status: ' . $response->status());
            } catch (\Exception $e) {
                Log::error('SintaSyncService: Exception while fetching SINTA API: ' . $e->getMessage());
            }

            return [];
        });
    }

    /**
     * Clean and normalize a person's name by removing academic titles,
     * non-alphanumeric characters, and extra spaces (case-insensitive).
     *
     * @param string $name
     * @return string
     */
    public function cleanName(string $name): string
    {
        $n = strtolower($name);

        // Common Indonesian & international academic titles and prefixes/suffixes
        $titles = [
            'prof', 'dr', 'drg', 'phd', 'ph.d', 'ph d', 'ir', 'apt', 'ns',
            's.kom', 'skom', 'm.kom', 'mkom',
            's.si', 'ssi', 'm.si', 'msi',
            's.t', 'st', 'm.t', 'mt',
            's.pd', 'spd', 'm.pd', 'mpd',
            's.e', 'se', 'm.m', 'mm', 'm.ba', 'mba',
            's.ked', 'sked', 'm.kes', 'mkes', 'sp.a', 'sp.p', 'sp.og', 'sp.pd', 'sp.b', 'sp.rad', 'sp.s', 'sp.m',
            's.h', 'sh', 'm.h', 'mh', 'm.kn', 'mkn',
            's.psi', 'spsi', 'm.psi', 'mpsi',
            's.sos', 'ssos', 'm.sos', 'msos',
            's.ip', 'sip', 'm.ip', 'mip',
            's.kep', 'skep', 'm.kep', 'mkep',
            's.farm', 'sfarm', 'm.farm', 'mfarm',
        ];

        foreach ($titles as $t) {
            $n = preg_replace('/\b' . preg_quote($t, '/') . '\.?\b/i', ' ', $n);
        }

        // Replace any remaining non-alphanumeric characters with space
        $n = preg_replace('/[^a-z0-9\s]/', ' ', $n);

        // Normalize spaces
        $n = preg_replace('/\s+/', ' ', $n);

        return trim($n);
    }

    /**
     * Phonetically normalize Indonesian name spelling variations.
     *
     * @param string $name
     * @return string
     */
    public function phoneticNormalize(string $name): string
    {
        $n = $this->cleanName($name);

        $replacements = [
            'muhammad' => 'muhamad',
            'mohammad' => 'muhamad',
            'mochammad' => 'muhamad',
            'moch' => 'muhamad',
            'm.' => 'muhamad',
            'achmad' => 'ahmad',
            'fathurrachman' => 'fathurahman',
            'faturrachman' => 'fathurahman',
            'fathurrahman' => 'fathurahman',
            'faturrahman' => 'fathurahman',
            'faturahman' => 'fathurahman',
            'ch' => 'c',
            'kh' => 'k',
            'sh' => 's',
            'dh' => 'd',
            'th' => 't',
            'oe' => 'u',
            'ie' => 'i',
            'dj' => 'j',
            'tj' => 'c',
            'rr' => 'r',
            'mm' => 'm',
            'nn' => 'n',
            'ff' => 'f',
            'll' => 'l',
            'ss' => 's',
            'tt' => 't',
            'pp' => 'p',
            'bb' => 'b',
            'dd' => 'd',
            'gg' => 'g',
            'kk' => 'k',
        ];

        $n = str_ireplace(array_keys($replacements), array_values($replacements), $n);
        return preg_replace('/\s+/', ' ', trim($n));
    }

    /**
     * Clean Google Scholar ID (extract pure ID if URL or query params are included).
     *
     * @param string|null $id
     * @return string|null
     */
    public function cleanScholarId(?string $id): ?string
    {
        if (!$id) return null;
        $id = trim($id);
        if (preg_match('/user=([a-zA-Z0-9_-]+)/', $id, $matches)) {
            return $matches[1];
        }
        $clean = preg_replace('/[&?].*$/', '', $id);
        return trim($clean) ?: null;
    }

    /**
     * Clean Scopus ID (extract digits only).
     *
     * @param string|null $id
     * @return string|null
     */
    public function cleanScopusId(?string $id): ?string
    {
        if (!$id) return null;
        $id = trim($id);
        if (preg_match('/authorId=([0-9]+)/', $id, $matches)) {
            return $matches[1];
        }
        $clean = preg_replace('/[^0-9]/', '', $id);
        return trim($clean) ?: null;
    }

    /**
     * Find the best matching lecturer in SINTA API data based on name.
     *
     * @param string $name
     * @param array|null $sintaList
     * @return array|null
     */
    public function findDosenByName(string $name, ?array $sintaList = null): ?array
    {
        if (empty($name)) return null;

        $list = $sintaList ?? $this->fetchSintaDosenList();
        if (empty($list)) return null;

        $cleanTarget = $this->cleanName($name);
        $phoneTarget = $this->phoneticNormalize($name);

        if (empty($cleanTarget)) return null;

        $bestMatch = null;
        $bestScore = 0;

        foreach ($list as $item) {
            $apiName = $item['name'] ?? '';
            $cleanApi = $this->cleanName($apiName);
            $phoneApi = $this->phoneticNormalize($apiName);

            // 1. Exact Clean Match (Case-insensitive)
            if ($cleanTarget === $cleanApi) {
                return [
                    'item' => $item,
                    'score' => 100,
                    'type' => 'exact',
                ];
            }

            // 2. Phonetic Exact Match
            if ($phoneTarget === $phoneApi) {
                return [
                    'item' => $item,
                    'score' => 98,
                    'type' => 'phonetic',
                ];
            }

            // 3. Substring word match
            $targetTokens = array_filter(explode(' ', $cleanTarget));
            $apiTokens = array_filter(explode(' ', $cleanApi));
            $commonTokens = array_intersect($targetTokens, $apiTokens);
            $tokenMatchCount = count($commonTokens);
            $maxTokens = max(count($targetTokens), count($apiTokens));
            $tokenScore = $maxTokens > 0 ? ($tokenMatchCount / $maxTokens) * 90 : 0;

            // 4. Similarity scoring
            similar_text($cleanTarget, $cleanApi, $simClean);
            similar_text($phoneTarget, $phoneApi, $simPhone);
            $sim = max($simClean, $simPhone, $tokenScore);

            if ($sim > $bestScore) {
                $bestScore = $sim;
                $bestMatch = [
                    'item' => $item,
                    'score' => $sim,
                    'type' => 'similarity',
                ];
            }
        }

        // Accept match if score is at least 75%
        if ($bestScore >= 75 && $bestMatch) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * Synchronize a specific User model with SINTA API data.
     *
     * @param User $user
     * @param bool $force (Overwrite even if already present)
     * @param array|null $sintaList
     * @return array
     */
    public function syncUser(User $user, bool $force = false, ?array $sintaList = null): array
    {
        $match = $this->findDosenByName($user->name, $sintaList);

        if (!$match || empty($match['item'])) {
            return [
                'success' => false,
                'message' => "Dosen '{$user->name}' tidak ditemukan di SINTA API.",
                'user' => $user,
            ];
        }

        $sintaDosen = $match['item'];
        $updated = false;

        $newScholarId = $this->cleanScholarId($sintaDosen['googleScholarId'] ?? null);
        $newScopusId = $this->cleanScopusId($sintaDosen['scopusAuthorId'] ?? null);
        $newFaculty = trim($sintaDosen['faculty'] ?? '');
        $newProdi = trim($sintaDosen['prodi'] ?? '');

        // Update Scholar ID if empty or forced
        if (($force || empty($user->scholar_id)) && !empty($newScholarId)) {
            $user->scholar_id = $newScholarId;
            $updated = true;
        }

        // Update Scopus ID if empty or forced
        if (($force || empty($user->scopus_id)) && !empty($newScopusId)) {
            $user->scopus_id = $newScopusId;
            $updated = true;
        }

        // Update Fakultas if empty or forced
        if (($force || empty($user->fakultas)) && !empty($newFaculty)) {
            $user->fakultas = $newFaculty;
            $updated = true;
        }

        // Update Program Studi if empty or forced
        if (($force || empty($user->program_studi)) && !empty($newProdi)) {
            $user->program_studi = $newProdi;
            $updated = true;
        }

        if ($updated) {
            $user->save();

            // Clear cache
            if (Cache::supportsTags()) {
                Cache::tags(['lecturers', 'stats', 'leaderboard'])->flush();
            } else {
                Cache::flush();
            }
        }

        return [
            'success' => true,
            'message' => "Data SINTA untuk '{$user->name}' berhasil disinkronisasi.",
            'match_info' => [
                'sinta_name' => $sintaDosen['name'] ?? '',
                'sinta_id' => $sintaDosen['id'] ?? '',
                'score' => round($match['score'], 1),
                'type' => $match['type'],
            ],
            'user' => $user,
            'updated' => $updated,
        ];
    }

    /**
     * Synchronize all lecturers in the system with SINTA API data.
     *
     * @param bool $force
     * @return array
     */
    public function syncAllUsers(bool $force = false): array
    {
        $sintaList = $this->fetchSintaDosenList(true);
        $dosenList = User::where('role', 'dosen')->get();

        $synced = 0;
        $notFound = 0;
        $details = [];

        foreach ($dosenList as $dosen) {
            $res = $this->syncUser($dosen, $force, $sintaList);
            if ($res['success']) {
                $synced++;
                $details[] = [
                    'id' => $dosen->id,
                    'name' => $dosen->name,
                    'status' => 'synced',
                    'scholar_id' => $dosen->scholar_id,
                    'scopus_id' => $dosen->scopus_id,
                    'fakultas' => $dosen->fakultas,
                    'program_studi' => $dosen->program_studi,
                    'match' => $res['match_info'] ?? null,
                ];
            } else {
                $notFound++;
                $details[] = [
                    'id' => $dosen->id,
                    'name' => $dosen->name,
                    'status' => 'not_found',
                    'message' => $res['message'],
                ];
            }
        }

        return [
            'total' => count($dosenList),
            'synced' => $synced,
            'not_found' => $notFound,
            'details' => $details,
        ];
    }
}

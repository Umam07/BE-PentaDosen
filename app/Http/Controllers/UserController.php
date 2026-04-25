<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use App\Models\ScholarData;
use App\Models\ScholarPublication;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{

    public function login(Request $request)
    {
        $username = $request->username ?? $request->email;
        $password = $request->password;

        if (empty($username) || empty($password)) {
            return response()->json(['error' => 'Username and password are required'], 400);
        }

        // 1. Try local database authentication first (for seeder users and testing)
        // This is fast and allows testing with dummy data even without LDAP access
        $user = User::where('email', $username)->first();
        if ($user && Hash::check($password, $user->password)) {
            // Generate Penta ID if it doesn't exist yet (for legacy users)
            if (!$user->penta_id) {
                $user->penta_id = $this->generatePentaId();
                $user->save();
            }

            \App\Models\ActivityLog::log($user->id, 'Login', 'User berhasil login ke sistem via Database Local');
            return response()->json(['user' => $user]);
        }

        // 2. Try LDAP authentication if local authentication fails or user is not found locally
        if (extension_loaded('ldap')) {
            $ldapHost = 'ldap://pdc.yarsi.ac.id:389';
            $ldapBaseDn = 'dc=yarsi,dc=ac,dc=id';

            $ldapConn = @ldap_connect($ldapHost);
            if ($ldapConn) {
                ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

                // Bind anonymously first to find the user DN
                $ldapBind = @ldap_bind($ldapConn);

                if ($ldapBind) {
                    // If user typed email, extract the username part for LDAP uid
                    $ldapUid = $username;
                    if (strpos($ldapUid, '@') !== false) {
                        $ldapUid = explode('@', $ldapUid)[0];
                    }

                    $filter = "(uid={$ldapUid})";
                    $attributes = ['dn', 'cn', 'displayName', 'description', 'title', 'uid', 'mail', 'email', 'telephonenumber'];

                    $search = @ldap_search($ldapConn, $ldapBaseDn, $filter, $attributes);
                    if ($search) {
                        $entries = @ldap_get_entries($ldapConn, $search);

                        if ($entries && $entries['count'] > 0 && !empty($entries[0]['dn'])) {
                            $userDn = $entries[0]['dn'];

                            // Verify password against LDAP
                            $isLdapBinded = @ldap_bind($ldapConn, $userDn, $password);

                            if ($isLdapBinded) {
                                // Successfully authenticated with LDAP
                                $ldapData = $entries[0];

                                $email = $ldapData['mail'][0] ?? ($ldapData['email'][0] ?? $username);
                                // Ensure it's a valid email format if it was just a username
                                if (strpos($email, '@') === false) {
                                    $email = $email . '@yarsi.ac.id';
                                }

                                $displayName = $ldapData['displayname'][0] ?? ($ldapData['cn'][0] ?? $ldapUid);
                                $titleCode = strtoupper($ldapData['title'][0] ?? '');
                                $idNik = $ldapData['description'][0] ?? null;
                                $phone = $ldapData['telephonenumber'][0] ?? null;

                                // Map role from LDAP title
                                $role = 'dosen'; // default fallback
                                if ($titleCode === 'M' || $titleCode === 'D') {
                                    $role = 'dosen';
                                } elseif ($titleCode === 'S') {
                                    $role = 'staf';
                                }

                                // Find user in local database by email or uid to sync
                                $user = User::where('email', $email)->orWhere('email', $username)->first();

                                if (!$user) {
                                    $user = new User();
                                    $user->email = $email;
                                    // Generate a random password since we NEVER save the LDAP password
                                    $user->password = Hash::make(\Illuminate\Support\Str::random(32));
                                }

                                $user->name = $displayName;
                                $user->nidn = $idNik ?: $user->nidn;
                                $user->phone = $phone ?: $user->phone;
                                $user->role = $role;

                                // Generate Penta ID if it doesn't exist
                                if (!$user->penta_id) {
                                    $user->penta_id = $this->generatePentaId();
                                }

                                $user->save();

                                \App\Models\ActivityLog::log($user->id, 'Login', 'User berhasil login ke sistem via LDAP');

                                return response()->json(['user' => $user]);
                            }
                        }
                    }
                }
            }
        }

        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    public function logout(Request $request)
    {
        $userId = $request->user_id;
        if ($userId) {
            \App\Models\ActivityLog::log($userId, 'Logout', 'User keluar dari sistem');
        }
        return response()->json(['success' => true]);
    }

    private function generatePentaId()
    {
        do {
            // Generate a random 7-digit number
            $pentaId = str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);
        } while (User::where('penta_id', $pentaId)->exists());

        return $pentaId;
    }

    public function profile($id)
    {
        $user = User::findOrFail($id);

        // Generate Penta ID if it doesn't exist yet (for legacy users)
        if (!$user->penta_id) {
            $user->penta_id = $this->generatePentaId();
            $user->save();
        }

        $scholarData = ScholarData::where('user_id', $user->id)->first();
        $scopusData = \App\Models\ScopusData::where('user_id', $user->id)->first();
        $publications = ScholarPublication::where('user_id', $user->id)->orderBy('year', 'desc')->get();
        $scopusPublications = \App\Models\ScopusPublication::where('user_id', $user->id)->orderBy('year', 'desc')->get();

        return response()->json([
            'user' => $user,
            'scholarData' => $scholarData,
            'scopusData' => $scopusData,
            'publications' => $publications,
            'scopusPublications' => $scopusPublications
        ]);
    }

    public function leaderboard()
    {
        $leaderboard = User::with(['scholarData', 'scopusData'])
            ->where('role', 'dosen')
            ->orderBy('total_kpi_points', 'desc')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'program_studi' => $u->program_studi,
                    'total_kpi_points' => $u->total_kpi_points,
                    'total_citations' => $u->scholarData->total_citations ?? 0,
                    'h_index' => $u->scholarData->h_index ?? 0,
                    'scopus_total_citations' => $u->scopusData->total_citations ?? 0,
                    'scopus_h_index' => $u->scopusData->h_index ?? 0,
                    'thumbnail' => $u->avatar ?? ($u->scholarData->thumbnail ?? null),
                ];
            });

        return response()->json(['leaderboard' => $leaderboard]);
    }

    public function chartProdi()
    {
        $data = User::where('role', 'dosen')
            ->whereNotNull('program_studi')
            ->where('program_studi', '!=', '')
            ->select(
                'program_studi',
                DB::raw('SUM(total_kpi_points) as total_points'),
                DB::raw('COUNT(*) as dosen_count')
            )
            ->groupBy('program_studi')
            ->get();

        // Add research count for each prodi
        $data = $data->map(function ($item) {
            $researchCount = \App\Models\Penelitian::whereHas('user', function ($query) use ($item) {
                $query->where('program_studi', $item->program_studi);
            })->count();

            $item->research_count = $researchCount;
            return $item;
        });

        return response()->json(['data' => $data]);
    }
    public function chartFakultas()
    {
        $data = User::where('role', 'dosen')
            ->whereNotNull('fakultas')
            ->where('fakultas', '!=', '')
            ->select('fakultas', DB::raw('SUM(total_kpi_points) as total_points'), DB::raw('COUNT(*) as dosen_count'))
            ->groupBy('fakultas')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function getStats()
    {
        $totalUsers = User::count();
        $totalDosen = User::where('role', 'dosen')->count();
        $totalPoints = User::sum('total_kpi_points');

        $totalDocs = \App\Models\Document::count();
        $approvedDocs = \App\Models\Document::where('status', 'Approved')->count();

        $totalCitations = \App\Models\ScholarData::sum('total_citations') + \App\Models\ScopusData::sum('total_citations');

        $topProdi = User::where('role', 'dosen')
            ->whereNotNull('program_studi')
            ->select('program_studi', DB::raw('SUM(total_kpi_points) as total_points'), DB::raw('COUNT(*) as count'))
            ->groupBy('program_studi')
            ->orderBy('total_points', 'desc')
            ->first();

        // Count unique prodi
        $totalProdi = User::where('role', 'dosen')
            ->whereNotNull('program_studi')
            ->where('program_studi', '!=', '')
            ->distinct('program_studi')
            ->count('program_studi');

        // Top Performer
        $topPerformer = User::where('role', 'dosen')
            ->orderBy('total_kpi_points', 'desc')
            ->first(['name', 'total_kpi_points']);

        // KPI Score 3 Years (Current year and 2 previous years)
        $currentYear = now()->year;
        $threeYearsAgo = $currentYear - 2;
        $kpiScore3Years = \App\Models\Document::where('status', 'Approved')
            ->whereYear('published_at', '>=', $threeYearsAgo)
            ->sum('awarded_points');

        // KPI Score This Year
        $kpiScoreThisYear = \App\Models\Document::where('status', 'Approved')
            ->whereYear('published_at', $currentYear)
            ->sum('awarded_points');

        $totalScholar = \App\Models\ScholarPublication::count();
        $totalScopus = \App\Models\ScopusPublication::count();
        $totalResearch = \App\Models\Penelitian::count();

        return response()->json([
            'total_users' => $totalUsers,
            'total_dosen' => $totalDosen,
            'total_prodi' => $totalProdi,
            'total_points' => $totalPoints,
            'total_docs' => $totalDocs,
            'approved_docs' => $approvedDocs,
            'total_citations' => $totalCitations,
            'top_prodi' => $topProdi,
            'top_performer' => $topPerformer,
            'kpi_score_3_years' => $kpiScore3Years,
            'kpi_score_this_year' => $kpiScoreThisYear,
            'total_scholar' => $totalScholar,
            'total_scopus' => $totalScopus,
            'total_research' => $totalResearch,
        ]);
    }
}

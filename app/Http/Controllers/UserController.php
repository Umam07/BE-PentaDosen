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
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'fakultas' => 'required|string|in:Fakultas Kedokteran,Fakultas Kedokteran Gigi,Fakultas Teknologi Informasi,Fakultas Ekonomi Bisnis,Fakultas Hukum,Fakultas Psikologi',
            'program_studi' => 'required|string|in:Kedokteran,Kedokteran Gigi,Teknik Informatika,Perpustakaan dan Sains Informasi,Manajemen,Akuntansi,Hukum,Psikologi',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'dosen',
            'fakultas' => $request->fakultas,
            'program_studi' => $request->program_studi,
            'total_kpi_points' => 0,
        ]);

        return response()->json(['user' => $user]);
    }

    public function login(Request $request)
    {
        $user = User::where('email', $request->email)->first();
        if ($user && Hash::check($request->password, $user->password)) {
            return response()->json(['user' => $user]);
        }
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    public function profile($id)
    {
        $user = User::findOrFail($id);
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
        $leaderboard = User::where('role', 'dosen')
            ->orderBy('total_kpi_points', 'desc')
            ->limit(10)
            ->get(['id', 'name', 'program_studi', 'total_kpi_points']);
        
        return response()->json(['leaderboard' => $leaderboard]);
    }

    public function chartProdi()
    {
        $data = User::where('role', 'dosen')
            ->whereNotNull('program_studi')
            ->where('program_studi', '!=', '')
            ->select('program_studi', DB::raw('SUM(total_kpi_points) as total_points'))
            ->groupBy('program_studi')
            ->get();
        
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

        return response()->json([
            'total_users' => $totalUsers,
            'total_dosen' => $totalDosen,
            'total_points' => $totalPoints,
            'total_docs' => $totalDocs,
            'approved_docs' => $approvedDocs,
            'total_citations' => $totalCitations,
            'top_prodi' => $topProdi,
        ]);
    }
}

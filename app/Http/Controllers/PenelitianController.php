<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Penelitian;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PenelitianController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'judul_penelitian' => 'required|string',
            'dana_disetujui' => 'required|numeric',
            'program' => 'required|in:hibah dikti,hibah internal,hibah luar negeri',
            'skema' => 'required|in:kompetisi,pembinaan',
            'fokus' => 'required|in:kesehatan,ekonomi',
            'tahun' => 'required|integer',
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $path = $request->file('file')->store('penelitian', 'public');
        $fileUrl = Storage::url($path);

        $penelitian = Penelitian::create([
            'user_id' => $request->user_id,
            'judul_penelitian' => $request->judul_penelitian,
            'dana_disetujui' => $request->dana_disetujui,
            'program' => $request->program,
            'skema' => $request->skema,
            'fokus' => $request->fokus,
            'tahun' => $request->tahun,
            'file_url' => $fileUrl,
            'status' => 'Pending',
            'awarded_points' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Penelitian berhasil diunggah. Menunggu verifikasi admin.',
            'penelitian' => $penelitian,
        ]);
    }

    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $role = $request->query('role');

        if ($role === 'admin') {
            // Admin only sees what prodi has verified
            $penelitian = Penelitian::where('status', 'Verified by Prodi')
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get();
        } elseif ($role === 'prodi') {
             $admin = User::find($userId);
             if ($admin && $admin->program_studi) {
                 // Prodi sees pending research from their own department
                 $penelitian = Penelitian::where('status', 'Pending')
                     ->whereHas('user', function($q) use ($admin) {
                         $q->where('program_studi', $admin->program_studi);
                     })->with('user')->orderBy('created_at', 'desc')->get();
             } else {
                 $penelitian = [];
             }
        } else {
            // Dosen sees their own research regardless of status
            $penelitian = Penelitian::where('user_id', $userId)->orderBy('created_at', 'desc')->get();
        }

        return response()->json([
            'success' => true,
            'penelitian' => $penelitian,
        ]);
    }

    public function verify(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:Approved,Rejected',
            'role' => 'nullable|string'
        ]);

        $penelitian = Penelitian::findOrFail($id);
        $role = $request->role;
        
        if ($request->status === 'Approved') {
            if ($role === 'prodi') {
                if ($penelitian->status !== 'Pending') {
                    return response()->json(['success' => false, 'message' => 'Penelitian sudah diverifikasi prodi atau tahap admin.'], 400);
                }
                $penelitian->status = 'Verified by Prodi';
                $penelitian->save();
                return response()->json(['success' => true, 'message' => 'Penelitian diverifikasi prodi. Menunggu persetujuan LPPM/Admin.']);
            }

            // Admin Approval Logic
            if ($penelitian->status !== 'Verified by Prodi') {
                return response()->json(['success' => false, 'message' => 'Penelitian harus diverifikasi prodi terlebih dahulu.'], 400);
            }

            $penelitian->status = 'Approved';
            
            // Calculate points
            $points = 0;
            if ($penelitian->program === 'hibah luar negeri') {
                $points += 60;
            } elseif ($penelitian->program === 'hibah dikti') {
                $points += 50; // External
            } elseif ($penelitian->program === 'hibah internal') {
                $points += 40;
            }

            // Rupiah points: 0.05 per million (juta rupiah)
            $jutaRupiah = $penelitian->dana_disetujui / 1000000;
            $points += $jutaRupiah * 0.05;

            $penelitian->awarded_points = $points;
            
            // Add points to user
            $user = User::find($penelitian->user_id);
            $user->increment('total_kpi_points', $points);
            
            $penelitian->save();
        } else {
            // Rejection
            $penelitian->status = 'Rejected';
            $penelitian->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Penelitian berhasil ' . ($request->status === 'Approved' ? 'disetujui/diverifikasi' : 'ditolak') . '.',
            'penelitian' => $penelitian,
        ]);
    }
}

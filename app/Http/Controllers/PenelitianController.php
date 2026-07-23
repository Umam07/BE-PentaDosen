<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Penelitian;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class PenelitianController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'judul_penelitian' => [
                'required',
                'string',
                Rule::unique('penelitian')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user_id);
                })
            ],
            'dana_disetujui' => 'required|numeric',
            'program' => 'required|in:hibah dikti,hibah internal,hibah luar negeri',
            'skema' => 'required|in:kompetisi,pembinaan',
            'fokus' => 'required|in:kesehatan,ekonomi',
            'tahun' => 'required|date',
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ], [
            'judul_penelitian.unique' => 'Penelitian dengan judul ini sudah terdaftar di sistem.'
        ]);

        $fileUrl = '-';
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('penelitian', 'public');
            $fileUrl = Storage::url($path);
        }

        // Calculate points if status is Approved (Admin input)
        $status = $request->status ?: 'Pending';
        $awardedPoints = 0;

        if ($status === 'Approved') {
            if ($request->program === 'hibah luar negeri') {
                $awardedPoints += 10;
            } elseif ($request->program === 'hibah dikti') {
                $awardedPoints += 6;
            } elseif ($request->program === 'hibah internal') {
                $awardedPoints += 3;
            }
        }

        $penelitian = Penelitian::create([
            'user_id' => $request->user_id,
            'judul_penelitian' => $request->judul_penelitian,
            'dana_disetujui' => $request->dana_disetujui,
            'program' => $request->program,
            'skema' => $request->skema,
            'fokus' => $request->fokus,
            'tahun' => $request->tahun,
            'file_url' => $fileUrl,
            'status' => $status,
            'awarded_points' => $awardedPoints,
        ]);

        \App\Models\DocumentHistory::create([
            'penelitian_id' => $penelitian->id,
            'user_id' => $request->user_id,
            'action' => 'Dokumen Diunggah',
        ]);

        if ($status === 'Approved' && $awardedPoints > 0) {
            $user = User::find($request->user_id);
            if ($user) {
                $user->recalculateKpiPoints();
            }
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(['penelitian', 'stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

        \App\Models\ActivityLog::log($request->user_id, 'Submit Research', 'User mengajukan hasil penelitian: ' . $request->judul_penelitian);

        // === NOTIFICATIONS ===
        // Notify Admin Fakultas about new research submission
        if ($status === 'Pending') {
            $dosen = User::find($request->user_id);
            $dosenName = $dosen ? $dosen->name : 'Dosen';
            $dosenFakultas = $dosen ? $dosen->fakultas : null;
            $dosenProdi = $dosen ? $dosen->program_studi : null;

            $adminFakultasList = User::where('role', 'admin fakultas')
                ->when($dosenFakultas, fn($q) => $q->where('fakultas', $dosenFakultas))
                ->when($dosenProdi, fn($q) => $q->where(function($sub) use ($dosenProdi) {
                    $sub->whereNull('program_studi')
                        ->orWhere('program_studi', '')
                        ->orWhere('program_studi', $dosenProdi);
                }))
                ->get();
            foreach ($adminFakultasList as $adminFakultas) {
                Notification::send(
                    $adminFakultas->id,
                    'penelitian_submitted',
                    'Penelitian Baru Masuk',
                    "Dosen {$dosenName} mengajukan penelitian baru: '{$request->judul_penelitian}'.",
                    ['penelitian_id' => $penelitian->id]
                );
            }
        }

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
        $all = $request->query('all');
        $cacheKey = "penelitian_index_{$userId}_{$role}_{$all}";

        $fetchData = function () use ($userId, $role, $all) {
            if ($role === 'admin penelitian') {
                $query = Penelitian::with('user');
                if ($all !== 'true') {
                    $query->where('status', 'Verified by Fakultas');
                }
                return $query->orderBy('created_at', 'desc')->get();
            } elseif ($role === 'admin fakultas') {
                  $admin = User::find($userId);
                  if ($admin && $admin->fakultas) {
                      $query = Penelitian::whereHas('user', function($q) use ($admin) {
                          $q->where('fakultas', $admin->fakultas);
                          if ($admin->program_studi) {
                              $q->where('program_studi', $admin->program_studi);
                          }
                      });
                      if ($all !== 'true') {
                          $query->where('status', 'Pending');
                      }
                      return $query->with('user')->orderBy('created_at', 'desc')->get();
                  } else {
                      return [];
                  }
            } else {
                // Dosen sees their own research regardless of status
                return Penelitian::where('user_id', $userId)->orderBy('created_at', 'desc')->get();
            }
        };

        if (Cache::supportsTags()) {
            $penelitian = Cache::tags(['penelitian'])->remember($cacheKey, 3600, $fetchData);
        } else {
            $penelitian = Cache::remember($cacheKey, 3600, $fetchData);
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
            'role' => 'nullable|string',
            'catatan' => 'nullable|string'
        ]);

        $penelitian = Penelitian::findOrFail($id);
        $role = $request->role;
        
        if ($request->status === 'Approved') {
            if ($role === 'admin fakultas') {
                if ($penelitian->status !== 'Pending') {
                    return response()->json(['success' => false, 'message' => 'Penelitian sudah diverifikasi fakultas atau tahap admin.'], 400);
                }
                $penelitian->status = 'Verified by Fakultas';
                $penelitian->save();

                \App\Models\DocumentHistory::create([
                    'penelitian_id' => $penelitian->id,
                    'user_id' => $request->admin_id ?? $penelitian->user_id,
                    'action' => 'Diverifikasi Fakultas',
                    'notes' => null
                ]);

                return response()->json(['success' => true, 'message' => 'Penelitian diverifikasi fakultas. Menunggu persetujuan LPPM/Admin.']);
            }

            // Admin Approval Logic
            if ($role !== 'admin penelitian' && $penelitian->status !== 'Verified by Fakultas') {
                return response()->json(['success' => false, 'message' => 'Penelitian harus diverifikasi fakultas terlebih dahulu.'], 400);
            }

            $penelitian->status = 'Approved';
            
            // Calculate points
            $points = 0;
            if ($penelitian->program === 'hibah luar negeri') {
                $points += 10;
            } elseif ($penelitian->program === 'hibah dikti') {
                $points += 6; // External
            } elseif ($penelitian->program === 'hibah internal') {
                $points += 3;
            }

            $penelitian->awarded_points = $points;
            
            $penelitian->save();

            // Recalculate user points
            $user = User::find($penelitian->user_id);
            if ($user) {
                $user->recalculateKpiPoints();
            }

            \App\Models\DocumentHistory::create([
                'penelitian_id' => $penelitian->id,
                'user_id' => $request->admin_id ?? $penelitian->user_id,
                'action' => 'Disetujui Admin Penelitian',
                'notes' => null
            ]);
        } else {
            // Rejection
            $penelitian->status = 'Rejected';
            $penelitian->catatan = $request->catatan;
            $penelitian->save();

            \App\Models\DocumentHistory::create([
                'penelitian_id' => $penelitian->id,
                'user_id' => $request->admin_id ?? $penelitian->user_id,
                'action' => 'Ditolak ' . ($role === 'admin fakultas' ? 'Fakultas' : 'Penelitian'),
                'notes' => $request->catatan ?? null
            ]);
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(['penelitian', 'stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

        if ($request->admin_id) {
            \App\Models\ActivityLog::log($request->admin_id, 'Verifikasi Penelitian', "Mengubah status penelitian '{$penelitian->judul_penelitian}' menjadi {$request->status}");
        }

        // === NOTIFICATIONS ===
        $judulPenelitian = $penelitian->judul_penelitian;
        $dosenUserId = $penelitian->user_id;

        if ($request->status === 'Approved' && $role === 'admin fakultas') {
            // Notify dosen: verified by fakultas
            Notification::send(
                $dosenUserId,
                'penelitian_verified_fakultas',
                'Penelitian Diverifikasi Fakultas',
                "Penelitian '{$judulPenelitian}' telah diverifikasi Fakultas. Menunggu persetujuan LPPM.",
                ['penelitian_id' => $penelitian->id]
            );
            // Notify Admin Penelitian
            $adminPenelitianList = User::where('role', 'admin penelitian')->get();
            foreach ($adminPenelitianList as $adminPenelitian) {
                Notification::send(
                    $adminPenelitian->id,
                    'penelitian_pending_lppm',
                    'Penelitian Siap Ditinjau LPPM',
                    "Penelitian '{$judulPenelitian}' telah diverifikasi Fakultas dan siap untuk ditinjau LPPM.",
                    ['penelitian_id' => $penelitian->id]
                );
            }
        } elseif ($request->status === 'Approved' && $role !== 'admin fakultas') {
            // Final approval by LPPM
            Notification::send(
                $dosenUserId,
                'penelitian_approved',
                'Penelitian Disetujui ✓',
                "Selamat! Penelitian '{$judulPenelitian}' telah disetujui dan poin sudah ditambahkan ke akun Anda.",
                ['penelitian_id' => $penelitian->id]
            );
        } elseif ($request->status === 'Rejected') {
            $catatan = $request->catatan ? " Catatan: {$request->catatan}" : '';
            Notification::send(
                $dosenUserId,
                'penelitian_rejected',
                'Penelitian Ditolak',
                "Penelitian '{$judulPenelitian}' ditolak oleh admin.{$catatan} Silakan perbaiki dan ajukan ulang.",
                ['penelitian_id' => $penelitian->id]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Penelitian berhasil ' . ($request->status === 'Approved' ? 'disetujui/diverifikasi' : 'ditolak') . '.',
            'penelitian' => $penelitian,
        ]);
    }

    public function uploadPdf(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $penelitian = Penelitian::findOrFail($id);

        $path = $request->file('file')->store('penelitian', 'public');
        $fileUrl = Storage::url($path);

        $penelitian->file_url = $fileUrl;
        $penelitian->save();

        \App\Models\DocumentHistory::create([
            'penelitian_id' => $penelitian->id,
            'user_id' => $penelitian->user_id,
            'action' => 'Dokumen Diperbarui',
        ]);

        if (Cache::supportsTags()) {
            Cache::tags(['penelitian'])->flush();
        } else {
            Cache::flush();
        }

        return response()->json([
            'success' => true,
            'message' => 'File PDF berhasil diunggah.',
            'penelitian' => $penelitian,
        ]);
    }

    public function update(Request $request, $id)
    {
        $penelitian = Penelitian::findOrFail($id);

        // Lock: cannot edit if already verified or approved
        if (in_array($penelitian->status, ['Verified by Fakultas', 'Approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Penelitian yang sudah diverifikasi/disetujui tidak dapat diubah.',
            ], 403);
        }

        $request->validate([
            'judul_penelitian' => [
                'required',
                'string',
                Rule::unique('penelitian')->where(function ($query) use ($request, $penelitian) {
                    return $query->where('user_id', $penelitian->user_id);
                })->ignore($penelitian->id),
            ],
            'dana_disetujui' => 'required|numeric',
            'program' => 'required|in:hibah dikti,hibah internal,hibah luar negeri',
            'skema' => 'required|in:kompetisi,pembinaan,lainnya',
            'fokus' => 'required|in:kesehatan,ekonomi,teknologi,sosial,lainnya',
            'tahun' => 'required|date',
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ], [
            'judul_penelitian.unique' => 'Penelitian dengan judul ini sudah terdaftar di sistem.',
        ]);

        $wasRejected = $penelitian->status === 'Rejected';

        // Update file if a new one is provided
        if ($request->hasFile('file')) {
            // Delete old file
            if ($penelitian->file_url && $penelitian->file_url !== '-' && $penelitian->file_url !== '') {
                $oldPath = str_replace('/storage/', '', $penelitian->file_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('file')->store('penelitian', 'public');
            $penelitian->file_url = Storage::url($path);
        }

        $penelitian->judul_penelitian = $request->judul_penelitian;
        $penelitian->dana_disetujui   = $request->dana_disetujui;
        $penelitian->program          = $request->program;
        $penelitian->skema            = $request->skema;
        $penelitian->fokus            = $request->fokus;
        $penelitian->tahun            = $request->tahun;

        if ($wasRejected) {
            $penelitian->status = 'Pending';
            $penelitian->catatan = null;
        }

        $penelitian->save();

        \App\Models\DocumentHistory::create([
            'penelitian_id' => $penelitian->id,
            'user_id' => $penelitian->user_id,
            'action' => 'Dokumen Diperbarui',
        ]);

        if ($wasRejected) {
            $dosen = \App\Models\User::find($penelitian->user_id);
            $dosenName = $dosen ? $dosen->name : 'Dosen';
            $dosenFakultas = $dosen ? $dosen->fakultas : null;

            $adminFakultasList = \App\Models\User::where('role', 'admin fakultas')
                ->when($dosenFakultas, fn($q) => $q->where('fakultas', $dosenFakultas))
                ->get();
            foreach ($adminFakultasList as $adminFakultas) {
                Notification::send(
                    $adminFakultas->id,
                    'penelitian_resubmitted',
                    'Penelitian Direvisi',
                    "Dosen {$dosenName} telah merevisi penelitian yang sebelumnya ditolak: '{$penelitian->judul_penelitian}'.",
                    ['penelitian_id' => $penelitian->id, 'user_id' => $penelitian->user_id]
                );
            }
        }

        if (Cache::supportsTags()) {
            Cache::tags(['penelitian'])->flush();
        } else {
            Cache::flush();
        }

        \App\Models\ActivityLog::log($penelitian->user_id, 'Update Research', 'Dosen memperbarui penelitian: ' . $penelitian->judul_penelitian);

        return response()->json([
            'success' => true,
            'message' => 'Penelitian berhasil diperbarui.',
            'penelitian' => $penelitian,
        ]);
    }

    public function destroy($id)
    {
        $penelitian = Penelitian::findOrFail($id);

        // Lock: cannot delete if already verified or approved
        if (in_array($penelitian->status, ['Verified by Fakultas', 'Approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Penelitian yang sudah diverifikasi/disetujui tidak dapat dihapus.',
            ], 403);
        }

        // Points will be recalculated after deletion below

        // Delete stored file
        if ($penelitian->file_url && $penelitian->file_url !== '-') {
            $relativePath = str_replace('/storage/', '', $penelitian->file_url);
            Storage::disk('public')->delete($relativePath);
        }

        $judulPenelitian = $penelitian->judul_penelitian;
        $userId = $penelitian->user_id;

        $penelitian->delete();

        $user = User::find($userId);
        if ($user) {
            $user->recalculateKpiPoints();
        }

        if (Cache::supportsTags()) {
            Cache::tags(['penelitian', 'stats', 'leaderboard', 'lecturers'])->flush();
        } else {
            Cache::flush();
        }

        \App\Models\ActivityLog::log($userId, 'Delete Research', 'Dosen menghapus penelitian: ' . $judulPenelitian);

        return response()->json([
            'success' => true,
            'message' => 'Penelitian berhasil dihapus.',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Notification;

class SupportTicketController extends Controller
{
    /**
     * Dosen mengirim pesan/tiket bantuan baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ], [
            'user_id.required' => 'User ID pengirim wajib diisi.',
            'message.required' => 'Isi pesan tidak boleh kosong.',
        ]);

        $ticket = SupportTicket::create([
            'user_id'     => $request->user_id,
            'subject'     => $request->subject,
            'message'     => $request->message,
            'status'      => 'menunggu',
        ]);

        \App\Models\ActivityLog::log(
            $request->user_id,
            'Kirim Pesan Support',
            'Dosen mengirim pesan bantuan ke admin: ' . ($request->subject ?: 'Tanpa Subjek')
        );

        return response()->json([
            'success' => true,
            'message' => 'Pesan Anda berhasil dikirim ke admin.',
            'ticket'  => $ticket,
        ], 201);
    }

    /**
     * Dosen melihat daftar riwayat pesan miliknya sendiri (urut terbaru).
     */
    public function index(Request $request)
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID diperlukan untuk mengambil riwayat pesan.',
            ], 400);
        }

        $tickets = SupportTicket::where('user_id', $userId)
            ->with(['repliedByAdmin:id,name,role'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'tickets' => $tickets,
        ]);
    }

    /**
     * Melihat detail satu tiket beserta balasan admin (untuk dosen/admin).
     */
    public function show(Request $request, $id)
    {
        $ticket = SupportTicket::with(['user:id,name,email,fakultas,program_studi', 'repliedByAdmin:id,name,role'])->find($id);

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Pesan tidak ditemukan.',
            ], 404);
        }

        // Authorization check: pastikan tiket milik user yang meminta atau diakses oleh admin
        $requestUserId = $request->query('user_id');
        $userRole = $request->query('role');

        if ($requestUserId && $ticket->user_id != $requestUserId && !in_array($userRole, ['super admin', 'admin penelitian', 'admin fakultas'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke pesan ini.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'ticket'  => $ticket,
        ]);
    }

    /**
     * ADMIN: List semua tiket dari semua dosen (dengan filter status & pagination / counts).
     */
    public function adminIndex(Request $request)
    {
        $role = $request->query('role');
        if ($role && !in_array($role, ['super admin', 'admin penelitian', 'admin fakultas'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak: khusus admin.'], 403);
        }

        $query = SupportTicket::with([
            'user:id,name,email,fakultas,program_studi',
            'repliedByAdmin:id,name,role'
        ]);

        // Filter berdasarkan status (menunggu/dibalas/selesai)
        $status = $request->query('status');
        if ($status && in_array(strtolower($status), ['menunggu', 'dibalas', 'selesai'])) {
            $query->where('status', strtolower($status));
        }

        // Filter pencarian berdasarkan nama dosen, email, subjek, atau pesan
        $search = $request->query('search');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderBy('created_at', 'desc');

        // Counts per status untuk tab filter & badge notifikasi admin
        $pendingCount = SupportTicket::where('status', 'menunggu')->count();
        $repliedCount = SupportTicket::where('status', 'dibalas')->count();
        $completedCount = SupportTicket::where('status', 'selesai')->count();
        $totalCount = SupportTicket::count();

        // Support optional pagination or full list
        $perPage = $request->query('per_page');
        if ($perPage) {
            $tickets = $query->paginate((int) $perPage);
        } else {
            $tickets = $query->get();
        }

        return response()->json([
            'success'       => true,
            'tickets'       => $tickets,
            'counts'        => [
                'menunggu' => $pendingCount,
                'dibalas'  => $repliedCount,
                'selesai'  => $completedCount,
                'total'    => $totalCount,
            ],
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * ADMIN: Detail satu tiket beserta data dosen pengirim.
     */
    public function adminShow(Request $request, $id)
    {
        $role = $request->query('role');
        if ($role && !in_array($role, ['super admin', 'admin penelitian', 'admin fakultas'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak: khusus admin.'], 403);
        }

        $ticket = SupportTicket::with([
            'user:id,name,email,fakultas,program_studi',
            'repliedByAdmin:id,name,role'
        ])->find($id);

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Pesan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'ticket'  => $ticket,
        ]);
    }

    /**
     * ADMIN: Kirim balasan pesan ke dosen (otomatis ubah status jadi 'dibalas' & pemicu notifikasi).
     */
    public function reply(Request $request, $id)
    {
        $request->validate([
            'admin_id'    => 'required|exists:users,id',
            'admin_reply' => 'required|string',
            'status'      => 'nullable|in:dibalas,selesai',
        ], [
            'admin_id.required'    => 'ID admin wajib diisi.',
            'admin_reply.required' => 'Isi balasan tidak boleh kosong.',
        ]);

        $ticket = SupportTicket::findOrFail($id);

        $newStatus = $request->status ?: 'dibalas';

        $ticket->update([
            'admin_reply' => $request->admin_reply,
            'replied_by'  => $request->admin_id,
            'replied_at'  => now(),
            'status'      => $newStatus,
        ]);

        // Kirim Notifikasi ke Dosen pengirim tiket (Poin 4)
        Notification::send(
            $ticket->user_id,
            'support_ticket_replied',
            'Balasan Pesan Support',
            'Admin membalas pesan Anda: ' . ($ticket->subject ?: 'Pesan Support'),
            ['ticket_id' => $ticket->id]
        );

        \App\Models\ActivityLog::log(
            $request->admin_id,
            'Balas Pesan Support',
            'Admin membalas pesan support dari Dosen ID ' . $ticket->user_id
        );

        $ticket->load(['user:id,name,email,fakultas,program_studi', 'repliedByAdmin:id,name,role']);

        return response()->json([
            'success' => true,
            'message' => 'Balasan berhasil dikirim ke dosen.',
            'ticket'  => $ticket,
        ]);
    }

    /**
     * ADMIN: Ubah status tiket secara manual (misal: tandai 'selesai').
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:menunggu,dibalas,selesai',
        ], [
            'status.required' => 'Status baru wajib diisi.',
            'status.in'       => 'Status harus bernilai menunggu, dibalas, atau selesai.',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->status = $request->status;
        $ticket->save();

        if ($request->admin_id) {
            \App\Models\ActivityLog::log(
                $request->admin_id,
                'Update Status Tiket',
                "Mengubah status tiket ID {$ticket->id} menjadi {$request->status}"
            );
        }

        $ticket->load(['user:id,name,email,fakultas,program_studi', 'repliedByAdmin:id,name,role']);

        return response()->json([
            'success' => true,
            'message' => 'Status pesan berhasil diperbarui.',
            'ticket'  => $ticket,
        ]);
    }
}

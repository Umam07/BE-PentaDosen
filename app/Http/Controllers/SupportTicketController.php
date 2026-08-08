<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Notification;

class SupportTicketController extends Controller
{
    /**
     * Helper privat untuk memastikan array messages terisi & kompatibel dengan data lama.
     */
    private function ensureMessagesArray(SupportTicket $ticket): SupportTicket
    {
        $messages = $ticket->messages;
        if (!is_array($messages) || empty($messages)) {
            $messages = [];
            // Initial message from Dosen
            $user = $ticket->user ?? User::find($ticket->user_id);
            $messages[] = [
                'id'          => 'msg_init_' . $ticket->id,
                'sender'      => 'user',
                'sender_id'   => (int) $ticket->user_id,
                'sender_name' => $user->name ?? 'Dosen',
                'sender_role' => $user->role ?? 'dosen',
                'message'     => $ticket->message,
                'image_url'   => $ticket->image_url,
                'created_at'  => $ticket->created_at ? $ticket->created_at->toIso8601String() : now()->toIso8601String(),
            ];

            // Admin reply if existed
            if ($ticket->admin_reply) {
                $admin = $ticket->repliedByAdmin ?? User::find($ticket->replied_by);
                $messages[] = [
                    'id'          => 'msg_reply_' . $ticket->id,
                    'sender'      => 'admin',
                    'sender_id'   => $ticket->replied_by ? (int) $ticket->replied_by : 1,
                    'sender_name' => $admin->name ?? 'Tim Admin',
                    'sender_role' => $admin->role ?? 'admin penelitian',
                    'message'     => $ticket->admin_reply,
                    'created_at'  => $ticket->replied_at ? $ticket->replied_at->toIso8601String() : ($ticket->updated_at ? $ticket->updated_at->toIso8601String() : now()->toIso8601String()),
                ];
            }
        }
        $ticket->setAttribute('messages', $messages);
        return $ticket;
    }

    /**
     * Dosen mengirim pesan/tiket bantuan baru (mendukung lampiran gambar maks 10MB).
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
            'image'   => 'nullable|file|image|mimes:jpeg,jpg,png,webp,gif|max:10240',
        ], [
            'user_id.required' => 'User ID pengirim wajib diisi.',
            'message.required' => 'Isi pesan tidak boleh kosong.',
            'image.image'      => 'File terlampir harus berupa gambar.',
            'image.max'        => 'Ukuran gambar tidak boleh melebihi 10 MB.',
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('support_tickets', 'public');
            $imageUrl = \Illuminate\Support\Facades\Storage::url($path);
        }

        $user = User::find($request->user_id);
        $initialMsg = [
            'id'          => 'msg_' . time() . '_1',
            'sender'      => 'user',
            'sender_id'   => (int) $request->user_id,
            'sender_name' => $user->name ?? 'Dosen',
            'sender_role' => $user->role ?? 'dosen',
            'message'     => $request->message,
            'image_url'   => $imageUrl,
            'created_at'  => now()->toIso8601String(),
        ];

        $ticket = SupportTicket::create([
            'user_id'   => $request->user_id,
            'subject'   => $request->subject,
            'message'   => $request->message,
            'image_url' => $imageUrl,
            'messages'  => [$initialMsg],
            'status'    => 'menunggu',
        ]);

        \App\Models\ActivityLog::log(
            $request->user_id,
            'Kirim Pesan Support',
            'Dosen mengirim pesan bantuan ke admin: ' . ($request->subject ?: 'Tanpa Subjek') . ($imageUrl ? ' (dengan lampiran gambar)' : '')
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

        $tickets->transform(function ($t) {
            return $this->ensureMessagesArray($t);
        });

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

        if ($requestUserId && $ticket->user_id != $requestUserId && !in_array($userRole, ['admin penelitian', 'admin fakultas'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke pesan ini.',
            ], 403);
        }

        $this->ensureMessagesArray($ticket);

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
        if ($role && !in_array($role, ['admin penelitian', 'admin fakultas'])) {
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
            $tickets->getCollection()->transform(function ($t) {
                return $this->ensureMessagesArray($t);
            });
        } else {
            $tickets = $query->get();
            $tickets->transform(function ($t) {
                return $this->ensureMessagesArray($t);
            });
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
        if ($role && !in_array($role, ['admin penelitian', 'admin fakultas'])) {
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

        $this->ensureMessagesArray($ticket);

        return response()->json([
            'success' => true,
            'ticket'  => $ticket,
        ]);
    }

    /**
     * Kirim pesan susulan / balasan lanjutan (bisa dipanggil oleh Dosen maupun Admin).
     */
    public function addMessage(Request $request, $id)
    {
        $request->validate([
            'sender_id' => 'required|exists:users,id',
            'sender'    => 'required|in:user,admin',
            'message'   => 'required|string',
            'image'     => 'nullable|file|image|mimes:jpeg,jpg,png,webp,gif|max:10240',
        ], [
            'sender_id.required' => 'ID pengirim wajib diisi.',
            'message.required'   => 'Pesan balasan tidak boleh kosong.',
            'image.image'        => 'File terlampir harus berupa gambar.',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $this->ensureMessagesArray($ticket);
        $messages = $ticket->messages ?: [];

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('support_tickets', 'public');
            $imageUrl = \Illuminate\Support\Facades\Storage::url($path);
        }

        $senderUser = User::find($request->sender_id);
        $senderRole = $request->sender === 'admin' ? ($senderUser->role ?? 'admin penelitian') : 'dosen';

        $newMessage = [
            'id'          => 'msg_' . time() . '_' . (count($messages) + 1),
            'sender'      => $request->sender,
            'sender_id'   => (int) $request->sender_id,
            'sender_name' => $senderUser->name ?? ($request->sender === 'admin' ? 'Tim Admin' : 'Dosen'),
            'sender_role' => $senderRole,
            'message'     => trim($request->message),
            'image_url'   => $imageUrl,
            'created_at'  => now()->toIso8601String(),
        ];

        $messages[] = $newMessage;
        $newStatus = $request->sender === 'admin' ? ($request->status ?: 'dibalas') : 'menunggu';

        $updateData = [
            'messages' => $messages,
            'status'   => $newStatus,
        ];

        if ($request->sender === 'admin') {
            $updateData['admin_reply'] = trim($request->message);
            $updateData['replied_by']  = $request->sender_id;
            $updateData['replied_at']  = now();

            // Notifikasi ke Dosen
            Notification::send(
                $ticket->user_id,
                'support_ticket_replied',
                'Balasan Pesan Support',
                'Admin membalas pesan Anda: ' . ($ticket->subject ?: 'Pesan Support'),
                ['ticket_id' => $ticket->id]
            );
        } else {
            // Notifikasi ke Admin / log activity untuk balasan Dosen
            \App\Models\ActivityLog::log(
                $request->sender_id,
                'Kirim Balasan Support',
                'Dosen mengirim pesan susulan pada tiket #' . $ticket->id
            );
        }

        $ticket->update($updateData);
        $ticket->load(['user:id,name,email,fakultas,program_studi', 'repliedByAdmin:id,name,role']);
        $this->ensureMessagesArray($ticket);

        return response()->json([
            'success' => true,
            'message' => 'Pesan berhasil ditambahkan ke percakapan.',
            'ticket'  => $ticket,
        ]);
    }

    /**
     * ADMIN: Kirim balasan pesan ke dosen (otomatis ubah status jadi 'dibalas' & pemicu notifikasi).
     */
    public function reply(Request $request, $id)
    {
        $request->merge([
            'sender'    => 'admin',
            'sender_id' => $request->admin_id,
            'message'   => $request->admin_reply,
        ]);

        return $this->addMessage($request, $id);
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
        $this->ensureMessagesArray($ticket);

        return response()->json([
            'success' => true,
            'message' => 'Status pesan berhasil diperbarui.',
            'ticket'  => $ticket,
        ]);
    }
}

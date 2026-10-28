<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
    /**
     * Get notifications for a specific user.
     * Returns newest 50, includes unread_count.
     */
    public function index(Request $request, $userId)
    {
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas']) && $authUser->id != $userId) {
            return response()->json(['error' => 'Akses ditolak: Anda tidak dapat melihat notifikasi pengguna lain.'], 403);
        }

        $user = \App\Models\User::find($userId);

        $notifications = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        if ($user && $user->role === 'admin fakultas') {
            $adminFakultas = $user->fakultas;
            $adminProdi = $user->program_studi;

            $notifications = $notifications->filter(function ($notif) use ($adminFakultas, $adminProdi) {
                $data = $notif->data;
                if (!is_array($data)) {
                    return true;
                }

                $docId = $data['doc_id'] ?? null;
                $penelitianId = $data['penelitian_id'] ?? null;

                if ($docId) {
                    $doc = \App\Models\Document::with('user')->find($docId);
                    if ($doc && $doc->user) {
                        if ($adminFakultas && $doc->user->fakultas !== $adminFakultas) {
                            return false;
                        }
                        if ($adminProdi && $doc->user->program_studi !== $adminProdi) {
                            return false;
                        }
                    }
                }

                if ($penelitianId) {
                    $pen = \App\Models\Penelitian::with('user')->find($penelitianId);
                    if ($pen && $pen->user) {
                        if ($adminFakultas && $pen->user->fakultas !== $adminFakultas) {
                            return false;
                        }
                        if ($adminProdi && $pen->user->program_studi !== $adminProdi) {
                            return false;
                        }
                    }
                }

                return true;
            })->values();
        }

        $unreadCount = $notifications->where('is_read', false)->count();

        return response()->json([
            'success'      => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(Request $request, $id)
    {
        $notification = Notification::findOrFail($id);
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas']) && $authUser->id != $notification->user_id) {
            return response()->json(['error' => 'Akses ditolak.'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllRead(Request $request, $userId)
    {
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas']) && $authUser->id != $userId) {
            return response()->json(['error' => 'Akses ditolak.'], 403);
        }

        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a single notification.
     */
    public function destroy(Request $request, $id)
    {
        $notification = Notification::findOrFail($id);
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas']) && $authUser->id != $notification->user_id) {
            return response()->json(['error' => 'Akses ditolak.'], 403);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Delete all read notifications for a user (cleanup).
     */
    public function clearAll(Request $request, $userId)
    {
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas']) && $authUser->id != $userId) {
            return response()->json(['error' => 'Akses ditolak.'], 403);
        }

        Notification::where('user_id', $userId)->delete();

        return response()->json(['success' => true]);
    }
}

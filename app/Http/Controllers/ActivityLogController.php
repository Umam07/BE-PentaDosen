<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin penelitian', 'admin fakultas'])) {
            return response()->json(['error' => 'Akses ditolak: hanya Admin yang dapat melihat riwayat aktivitas.'], 403);
        }

        $perPage = $request->query('per_page', 10);
        $actionFilter = $request->query('action');
        
        $query = ActivityLog::with('user');

        if ($authUser && $authUser->role === 'admin fakultas' && $authUser->fakultas) {
            $query->whereHas('user', function ($u) use ($authUser) {
                $u->where('fakultas', $authUser->fakultas);
            });
        }

        if ($actionFilter) {
            if ($actionFilter === 'create') {
                $query->where(function($q) {
                    $q->where('action', 'like', 'Submit%')
                      ->orWhere('action', 'like', 'Upload%');
                });
            } else {
                $query->where('action', 'like', '%' . $actionFilter . '%');
            }
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);
        return response()->json([
            'logs' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total(),
            'per_page' => $logs->perPage(),
        ]);
    }

    public function store(Request $request)
    {
        $authUser = $request->user();
        $targetUserId = $authUser ? $authUser->id : $request->user_id;

        $request->validate([
            'user_id' => $authUser ? 'nullable' : 'required',
            'action' => 'required',
            'description' => 'nullable'
        ]);

        ActivityLog::log($targetUserId, $request->action, $request->description);

        return response()->json(['success' => true]);
    }
}

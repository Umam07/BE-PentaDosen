<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 10);
        $actionFilter = $request->query('action');
        
        $query = ActivityLog::with('user');

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
        $request->validate([
            'user_id' => 'required',
            'action' => 'required',
            'description' => 'nullable'
        ]);

        ActivityLog::log($request->user_id, $request->action, $request->description);

        return response()->json(['success' => true]);
    }
}

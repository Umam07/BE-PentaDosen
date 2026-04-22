<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = ActivityLog::with('user')->orderBy('created_at', 'desc')->get();
        return response()->json(['logs' => $logs]);
    }
}

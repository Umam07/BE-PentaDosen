<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\SintaSyncService;

class SintaController extends Controller
{
    protected SintaSyncService $sintaService;

    public function __construct(SintaSyncService $sintaService)
    {
        $this->sintaService = $sintaService;
    }

    /**
     * Sync single user with SINTA API data by user ID.
     */
    public function syncUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $force = $request->boolean('force', false);

        $result = $this->sintaService->syncUser($user, $force);

        if (!$result['success']) {
            return response()->json($result, 404);
        }

        return response()->json($result);
    }

    /**
     * Admin bulk sync all lecturers with SINTA API data.
     */
    public function syncAll(Request $request)
    {
        $force = $request->boolean('force', false);
        $result = $this->sintaService->syncAllUsers($force);

        return response()->json([
            'success' => true,
            'message' => "Sinkronisasi selesai: {$result['synced']} dosen berhasil disinkronkan dari total {$result['total']} dosen.",
            'data' => $result
        ]);
    }

    /**
     * Check/test matching for any arbitrary name against SINTA.
     */
    public function checkName(Request $request)
    {
        $name = $request->query('name');
        if (empty($name)) {
            return response()->json(['error' => 'Parameter name diperlukan.'], 400);
        }

        $match = $this->sintaService->findDosenByName($name);

        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => "Tidak ditemukan dosen SINTA yang cocok untuk nama '{$name}'."
            ], 404);
        }

        return response()->json([
            'success' => true,
            'match' => $match
        ]);
    }

    /**
     * Get cached list of SINTA lecturers.
     */
    public function getDosenList(Request $request)
    {
        $fresh = $request->boolean('fresh', false);
        $list = $this->sintaService->fetchSintaDosenList($fresh);

        return response()->json([
            'total' => count($list),
            'dosen' => $list
        ]);
    }
}

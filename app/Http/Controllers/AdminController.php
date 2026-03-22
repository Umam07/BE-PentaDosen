<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Document;
use App\Models\User;
use App\Models\PointWeight;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function getPendingDocuments()
    {
        $docs = Document::with('user')
            ->where('status', 'Pending')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($doc) {
                return array_merge($doc->toArray(), ['user_name' => $doc->user->name]);
            });

        return response()->json(['documents' => $docs]);
    }

    public function getAllDocuments()
    {
        $docs = Document::with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return array_merge($doc->toArray(), ['user_name' => $doc->user->name]);
            });

        return response()->json(['documents' => $docs]);
    }

    public function getAllLecturers()
    {
        $lecturers = User::with(['scholarData', 'scopusData'])
            ->where('role', 'dosen')
            ->orderBy('name', 'asc')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'program_studi' => $u->program_studi,
                    'scholar_id' => $u->scholar_id,
                    'scopus_id' => $u->scopus_id,
                    'total_kpi_points' => $u->total_kpi_points,
                    'total_citations' => $u->scholarData->total_citations ?? 0,
                    'h_index' => $u->scholarData->h_index ?? 0,
                    'i10_index' => $u->scholarData->i10_index ?? 0,
                    'last_synced' => $u->scholarData->last_synced ?? null,
                    'thumbnail' => $u->scholarData->thumbnail ?? null,
                    'scopus_total_citations' => $u->scopusData->total_citations ?? 0,
                    'scopus_h_index' => $u->scopusData->h_index ?? 0,
                    'scopus_document_count' => $u->scopusData->document_count ?? 0,
                    'scopus_last_synced' => $u->scopusData->last_synced ?? null,
                ];
            });

        return response()->json(['lecturers' => $lecturers]);
    }

    public function verifyDocument(Request $request, $id)
    {
        $status = $request->status; // 'Approved' or 'Rejected'
        $doc = Document::findOrFail($id);

        if ($status === 'Approved') {
            $weight = PointWeight::where('category', $doc->category)->first();
            $categoryPoints = $weight ? $weight->weight_value : 0;

            // Only award KPI points if document is within accreditation period
            $points = $doc->is_kpi_counted ? $categoryPoints : 0;

            DB::transaction(function () use ($doc, $status, $points) {
                $doc->update([
                    'status' => $status,
                    'awarded_points' => $points
                ]);
                if ($points > 0) {
                    $doc->user->increment('total_kpi_points', $points);
                }
            });
        } else {
            $doc->update(['status' => $status]);
        }

        return response()->json(['success' => true]);
    }

    public function bulkUpdateScholar(Request $request)
    {
        $lecturers = $request->lecturers; // Expecting array of {id, scholar_id}

        if (!is_array($lecturers)) {
            return response()->json(['error' => 'Invalid data format'], 400);
        }

        DB::transaction(function () use ($lecturers) {
            foreach ($lecturers as $l) {
                if (isset($l['id'])) {
                    $scholar_id = isset($l['scholar_id']) && trim($l['scholar_id']) !== '' ? trim($l['scholar_id']) : null;
                    User::where('id', $l['id'])->update(['scholar_id' => $scholar_id]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    public function bulkUpdateScopus(Request $request)
    {
        $lecturers = $request->lecturers; // Expecting array of {id, scopus_id}

        if (!is_array($lecturers)) {
            return response()->json(['error' => 'Invalid data format'], 400);
        }

        DB::transaction(function () use ($lecturers) {
            foreach ($lecturers as $l) {
                if (isset($l['id'])) {
                    $scopus_id = isset($l['scopus_id']) && trim($l['scopus_id']) !== '' ? trim($l['scopus_id']) : null;
                    User::where('id', $l['id'])->update(['scopus_id' => $scopus_id]);
                }
            }
        });

        return response()->json(['success' => true]);
    }
}

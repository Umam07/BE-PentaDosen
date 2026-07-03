<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Document;
use App\Models\PointWeight;
use App\Models\ScholarPublication;
use App\Models\ScopusPublication;
use App\Models\DocumentHistory;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DocumentController extends Controller
{
    // Dynamic KPI Active period helpers
    private function getKpiPeriodStart()
    {
        return \App\Models\SystemSetting::getValue('kpi_period_start', '2025-01-01');
    }
    private function getKpiPeriodEnd()
    {
        return \App\Models\SystemSetting::getValue('kpi_period_end', '2027-12-31');
    }
    private function getKpiPeriodLabel()
    {
        return \App\Models\SystemSetting::getValue('kpi_period_label', '2025-2027');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'title' => 'required',
            'category' => 'required',
            'published_at' => 'required|date',
            'doc_type' => 'required|in:kpi,arsip',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'is_corresponding' => 'nullable|boolean',
        ]);

        // Duplicate check
        $existingDuplicate = Document::where('user_id', $request->user_id)
            ->where('title', $request->title)
            ->first();

        if ($existingDuplicate && $existingDuplicate->file_url !== '') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'title' => ['Dokumen dengan judul ini sudah terdaftar di sistem.']
                ]
            ], 422);
        }

        $fileUrl = '-';
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('uploads', 'public');
            $fileUrl = Storage::url($path);
        }

        $publishedAt = Carbon::parse($request->published_at);
        $docType = $request->doc_type;
        $requestedStatus = $request->status; // Status sent by admin

        // Determine KPI status
        $isKpi = false;
        $accreditationPeriod = null;

        if ($docType === 'kpi') {
            $periodStart = Carbon::parse($this->getKpiPeriodStart());
            $periodEnd = Carbon::parse($this->getKpiPeriodEnd());
            $isKpi = $publishedAt->between($periodStart, $periodEnd);
            $accreditationPeriod = $this->getKpiPeriodLabel();
        }
        // If docType is 'arsip', isKpi stays false, no period

        // Check auto-verification: match title with Scholar/Scopus publications
        $autoVerified = false;
        $awardedPoints = 0;

        // If status is sent as Approved (Admin upload), set it
        if ($requestedStatus === 'Approved') {
            $autoVerified = true;
            $weight = PointWeight::where('category', $request->category)->first();
            $awardedPoints = $weight ? $weight->weight_value : 0;

            if ($request->category === 'HKI Hak Cipta') {
                $year = $publishedAt->year;
                $approvedCount = Document::where('user_id', $request->user_id)
                    ->where('category', 'HKI Hak Cipta')
                    ->where('status', 'Approved')
                    ->whereYear('published_at', $year)
                    ->count();
                if ($approvedCount >= 2) {
                    $awardedPoints = 0;
                }
            }
        } elseif ($isKpi) {
            $titleNormalized = strtolower(trim($request->title));

            // Check Google Scholar publications
            $scholarMatch = ScholarPublication::where('user_id', $request->user_id)
                ->whereRaw('LOWER(TRIM(title)) = ?', [$titleNormalized])
                ->exists();

            // Check Scopus publications
            $scopusMatch = ScopusPublication::where('user_id', $request->user_id)
                ->whereRaw('LOWER(TRIM(title)) = ?', [$titleNormalized])
                ->exists();

            if ($scholarMatch || $scopusMatch) {
                $autoVerified = true;
                $weight = PointWeight::where('category', $request->category)->first();
                $awardedPoints = $weight ? $weight->weight_value : 0;

                if ($request->category === 'HKI Hak Cipta') {
                    $year = $publishedAt->year;
                    $approvedCount = Document::where('user_id', $request->user_id)
                        ->where('category', 'HKI Hak Cipta')
                        ->where('status', 'Approved')
                        ->whereYear('published_at', $year)
                        ->count();
                    if ($approvedCount >= 2) {
                        $awardedPoints = 0;
                    }
                }
            }
        }

        $doc = DB::transaction(function () use ($request, $fileUrl, $publishedAt, $isKpi, $accreditationPeriod, $autoVerified, $awardedPoints) {

            // Check for existing auto-synced document without file
            $existingDoc = Document::where('user_id', $request->user_id)
                ->where('title', $request->title)
                ->where('file_url', '')
                ->first();

            if ($existingDoc) {
                // If it already exists (from auto-sync), just update the file_url and category
                // We don't re-add points to avoid duplication, since points were already added during sync
                $existingDoc->update([
                    'category' => $request->category,
                    'file_url' => $fileUrl,
                    'published_at' => $publishedAt->format('Y-m-d'),
                    'is_kpi_counted' => $isKpi,
                    'accreditation_period' => $accreditationPeriod,
                    'status' => $autoVerified ? 'Approved' : 'Pending',
                    'is_corresponding' => $request->boolean('is_corresponding', $existingDoc->is_corresponding),
                    'hki_type' => $request->hki_type,
                    'inventor_name' => $request->inventor_name,
                ]);
                $doc = $existingDoc;
            } else {
                $doc = Document::create([
                    'user_id' => $request->user_id,
                    'title' => $request->title,
                    'category' => $request->category,
                    'file_url' => $fileUrl,
                    'published_at' => $publishedAt->format('Y-m-d'),
                    'is_kpi_counted' => $isKpi,
                    'accreditation_period' => $accreditationPeriod,
                    'status' => $autoVerified ? 'Approved' : 'Pending',
                    'awarded_points' => $awardedPoints,
                    'is_corresponding' => $request->boolean('is_corresponding', false),
                    'hki_type' => $request->hki_type,
                    'inventor_name' => $request->inventor_name,
                ]);

                // If auto-verified, update user's total KPI points
                if ($autoVerified) {
                    $totalDocPoints = Document::where('user_id', $request->user_id)
                        ->where('status', 'Approved')
                        ->sum('awarded_points');
                    $totalPenPoints = \App\Models\Penelitian::where('user_id', $request->user_id)
                        ->where('status', 'Approved')
                        ->sum('awarded_points');
                    $totalScopusPoints = \App\Models\ScopusPublication::where('user_id', $request->user_id)
                        ->sum('awarded_points');

                    $doc->user->update([
                        'total_kpi_points' => round($totalDocPoints + $totalPenPoints + $totalScopusPoints)
                    ]);
                }
            }

            // Log History
            DocumentHistory::create([
                'document_id' => $doc->id,
                'user_id' => $request->user_id,
                'action' => $existingDoc ? 'Dokumen Diperbarui' : 'Dokumen Diunggah',
            ]);

            return $doc;
        });

        $actionName = 'Submit Document';
        if (str_contains(strtolower($request->category), 'jurnal')) {
            $actionName = 'Submit Journal';
        } elseif (str_contains(strtolower($request->category), 'hki')) {
            $actionName = 'Submit HKI';
        } elseif (str_contains(strtolower($request->category), 'buku')) {
            $actionName = 'Submit Book';
        }

        \App\Models\ActivityLog::log($request->user_id, $actionName, 'User mengajukan ' . $request->category . ': ' . $request->title);

        // === NOTIFICATIONS ===
        // Notify admin fakultas about new document submission
        if (!$autoVerified) {
            $dosen = User::find($request->user_id);
            $dosenName = $dosen ? $dosen->name : 'Dosen';
            $dosenFakultas = $dosen ? $dosen->fakultas : null;

            // Notify Admin Fakultas of the same fakultas
            $adminFakultasList = User::where('role', 'admin fakultas')
                ->when($dosenFakultas, fn($q) => $q->where('fakultas', $dosenFakultas))
                ->get();
            foreach ($adminFakultasList as $adminFakultas) {
                Notification::send(
                    $adminFakultas->id,
                    'doc_submitted',
                    'Dokumen Baru Masuk',
                    "Dosen {$dosenName} mengajukan dokumen baru: '{$request->title}' ({$request->category}).",
                    ['doc_id' => $doc->id, 'user_id' => $request->user_id]
                );
            }
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$request->user_id}", 'documents', 'admin_documents', 'stats'])->flush();
        } else {
            Cache::forget("user_documents_{$request->user_id}");
            Cache::flush();
        }

        return response()->json([
            'success' => true,
            'docId' => $doc->id,
            'auto_verified' => $autoVerified,
            'message' => $autoVerified
                ? 'Dokumen diunggah dan otomatis terverifikasi (cocok dengan publikasi Scopus/Scholar).'
                : 'Dokumen berhasil diunggah. Menunggu verifikasi admin.',
        ]);
    }

    public function getUserDocuments($id)
    {
        $cacheKey = "user_documents_{$id}";
        $fetchData = function () use ($id) {
            return Document::with('penelitian')->where('user_id', $id)->orderBy('published_at', 'desc')->get();
        };

        if (Cache::supportsTags()) {
            $documents = Cache::tags(['documents', "user_documents_{$id}"])->remember($cacheKey, 3600, $fetchData);
        } else {
            $documents = Cache::remember($cacheKey, 3600, $fetchData);
        }

        return response()->json([
            'success' => true,
            'documents' => $documents,
        ]);
    }

    public function getWeights()
    {
        $weights = PointWeight::all();
        return response()->json(['weights' => $weights]);
    }

    public function getAccreditationPeriodsApi()
    {
        return response()->json([
            'kpi_period' => [
                'label' => $this->getKpiPeriodLabel(),
                'start' => $this->getKpiPeriodStart(),
                'end' => $this->getKpiPeriodEnd(),
            ],
        ]);
    }

    public function uploadPdf(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $doc = Document::findOrFail($id);

        $path = $request->file('file')->store('uploads', 'public');
        $fileUrl = Storage::url($path);

        $doc->file_url = $fileUrl;
        $doc->save();

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$doc->user_id}", 'documents', 'admin_documents'])->flush();
        } else {
            Cache::forget("user_documents_{$doc->user_id}");
            Cache::flush();
        }

        return response()->json([
            'success' => true,
            'message' => 'File berhasil diunggah.',
            'document' => $doc,
        ]);
    }

    public function linkToPenelitian(Request $request, $id)
    {
        $request->validate([
            'penelitian_id' => 'required|exists:penelitian,id'
        ]);

        $doc = Document::findOrFail($id);
        $doc->penelitian_id = $request->penelitian_id;
        $doc->save();

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$doc->user_id}", 'documents', 'admin_documents'])->flush();
        } else {
            Cache::forget("user_documents_{$doc->user_id}");
            Cache::flush();
        }

        return response()->json([
            'success' => true,
            'message' => 'Dokumen berhasil dihubungkan ke penelitian.',
            'document' => $doc->load('penelitian')
        ]);
    }

    public function getApprovedPenelitian($userId)
    {
        $penelitian = \App\Models\Penelitian::where('user_id', $userId)
            ->where('status', 'Approved')
            ->orderBy('tahun', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'penelitian' => $penelitian
        ]);
    }

    public function update(Request $request, $id)
    {
        $doc = Document::findOrFail($id);

        // Lock: cannot edit if already verified or approved
        if (in_array($doc->status, ['Verified by Fakultas', 'Approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen yang sudah diverifikasi/disetujui tidak dapat diubah.',
            ], 403);
        }

        $request->validate([
            'title'        => 'required|string',
            'category'     => 'required|string',
            'published_at' => 'required|date',
            'doc_type'     => 'required|in:kpi,arsip',
            'file'         => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'is_corresponding' => 'nullable|boolean',
            'hki_type' => 'nullable|string',
            'inventor_name' => 'nullable|string',
        ]);

        $publishedAt = Carbon::parse($request->published_at);

        // Determine KPI status
        $isKpi = false;
        $accreditationPeriod = null;

        if ($request->doc_type === 'kpi') {
            $periodStart = Carbon::parse($this->getKpiPeriodStart());
            $periodEnd   = Carbon::parse($this->getKpiPeriodEnd());
            $isKpi       = $publishedAt->between($periodStart, $periodEnd);
            $accreditationPeriod = $this->getKpiPeriodLabel();
        }

        // Update file if a new one is provided
        if ($request->hasFile('file')) {
            // Delete old file
            if ($doc->file_url && $doc->file_url !== '-' && $doc->file_url !== '') {
                $oldPath = str_replace('/storage/', '', $doc->file_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('file')->store('uploads', 'public');
            $doc->file_url = Storage::url($path);
        }

        $wasRejected = $doc->status === 'Rejected';

        $doc->title               = $request->title;
        $doc->category            = $request->category;
        $doc->published_at        = $publishedAt->format('Y-m-d');
        $doc->is_kpi_counted      = $isKpi;
        $doc->accreditation_period = $accreditationPeriod;
        $doc->is_corresponding    = $request->boolean('is_corresponding', $doc->is_corresponding);
        $doc->hki_type = $request->hki_type;
        $doc->inventor_name = $request->inventor_name;

        if ($wasRejected) {
            $doc->status = 'Pending';
            $doc->catatan = null;
        }

        $doc->save();

        DocumentHistory::create([
            'document_id' => $doc->id,
            'user_id' => $doc->user_id,
            'action' => $wasRejected ? 'Dokumen Direvisi & Diajukan Ulang' : 'Dokumen Diperbarui',
        ]);

        if ($wasRejected) {
            $dosen = \App\Models\User::find($doc->user_id);
            $dosenName = $dosen ? $dosen->name : 'Dosen';
            $dosenFakultas = $dosen ? $dosen->fakultas : null;

            $adminFakultasList = \App\Models\User::where('role', 'admin fakultas')
                ->when($dosenFakultas, fn($q) => $q->where('fakultas', $dosenFakultas))
                ->get();
            foreach ($adminFakultasList as $adminFakultas) {
                Notification::send(
                    $adminFakultas->id,
                    'doc_resubmitted',
                    'Dokumen Direvisi',
                    "Dosen {$dosenName} telah merevisi dokumen yang sebelumnya ditolak: '{$doc->title}'.",
                    ['doc_id' => $doc->id, 'user_id' => $doc->user_id]
                );
            }
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$doc->user_id}", 'documents', 'admin_documents', 'stats'])->flush();
        } else {
            Cache::forget("user_documents_{$doc->user_id}");
            Cache::flush();
        }

        \App\Models\ActivityLog::log($doc->user_id, 'Update Document', 'Dosen memperbarui dokumen: ' . $doc->title);

        return response()->json([
            'success'  => true,
            'message'  => 'Dokumen berhasil diperbarui.',
            'document' => $doc,
        ]);
    }

    public function destroy($id)
    {
        $doc = Document::findOrFail($id);

        // Lock: cannot delete if already verified or approved
        if (in_array($doc->status, ['Verified by Fakultas', 'Approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen yang sudah diverifikasi/disetujui tidak dapat dihapus.',
            ], 403);
        }

        // No inline decrement here, we'll recalculate the sum after delete

        // Delete stored file
        if ($doc->file_url && $doc->file_url !== '-' && $doc->file_url !== '') {
            $relativePath = str_replace('/storage/', '', $doc->file_url);
            Storage::disk('public')->delete($relativePath);
        }

        $docTitle = $doc->title;
        $userId   = $doc->user_id;

        $doc->delete();

        // Recalculate total kpi points (sum documents and penelitian)
        $user = User::find($userId);
        if ($user) {
            $totalDocPoints = Document::where('user_id', $userId)
                ->where('status', 'Approved')
                ->sum('awarded_points');
            $totalPenPoints = \App\Models\Penelitian::where('user_id', $userId)
                ->where('status', 'Approved')
                ->sum('awarded_points');
            $totalScopusPoints = \App\Models\ScopusPublication::where('user_id', $userId)
                ->sum('awarded_points');

            $user->update(['total_kpi_points' => round($totalDocPoints + $totalPenPoints + $totalScopusPoints)]);
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$userId}", 'documents', 'admin_documents', 'stats'])->flush();
        } else {
            Cache::forget("user_documents_{$userId}");
            Cache::flush();
        }

        \App\Models\ActivityLog::log($userId, 'Delete Document', 'Dosen menghapus dokumen: ' . $docTitle);

        return response()->json([
            'success' => true,
            'message' => 'Dokumen berhasil dihapus.',
        ]);
    }

    public function getHistory($id)
    {
        $doc = Document::findOrFail($id);
        $history = $doc->history()->with('user:id,name,role')->get();
        return response()->json([
            'success' => true,
            'history' => $history
        ]);
    }
}


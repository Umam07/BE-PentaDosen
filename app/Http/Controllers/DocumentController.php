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
        return \App\Models\SystemSetting::getValue('kpi_period_start', '2026-01-01');
    }
    private function getKpiPeriodEnd()
    {
        return \App\Models\SystemSetting::getValue('kpi_period_end', '2026-12-31');
    }
    private function getKpiPeriodLabel()
    {
        return \App\Models\SystemSetting::getValue('kpi_period_label', '2026');
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
            'sinta_rank' => 'nullable|string',
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
                    'sinta_rank' => $request->sinta_rank ?? $existingDoc->sinta_rank,
                    'is_sinta_confirmed' => $request->filled('sinta_rank') ? true : $existingDoc->is_sinta_confirmed,
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
                    'sinta_rank' => $request->sinta_rank,
                    'is_sinta_confirmed' => $request->filled('sinta_rank') ? true : false,
                ]);

                // If auto-verified, update user's total KPI points
                if ($autoVerified) {
                    $doc->user->recalculateKpiPoints();
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
            $dosenProdi = $dosen ? $dosen->program_studi : null;

            // Notify Admin Fakultas of the same fakultas and prodi (if set)
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
            $scopusPubs = \App\Models\ScopusPublication::where('user_id', $id)->get();
            $scopusMap = [];
            foreach ($scopusPubs as $sp) {
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $sp->title));
                if ($norm) {
                    $scopusMap[$norm] = $sp;
                }
            }

            $scholarPubs = \App\Models\ScholarPublication::where('user_id', $id)->get();
            $scholarMap = [];
            foreach ($scholarPubs as $sp) {
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $sp->title));
                if ($norm) {
                    $scholarMap[$norm] = $sp;
                }
            }

            $manualDocs = Document::with('penelitian')->where('user_id', $id)->orderBy('published_at', 'desc')->get()->map(function($doc) use ($scopusMap, $scholarMap) {
                $arr = $doc->toArray();
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $doc->title));

                if (isset($scholarMap[$norm])) {
                    $sp = $scholarMap[$norm];
                    $citations = (int)($sp->citations ?? 0);
                    $arr['source'] = 'scholar';
                    $arr['citations'] = $citations;
                    $arr['source_id'] = $sp->id;
                    $arr['category'] = 'Jurnal Nasional';
                    $arr['awarded_points'] = (int)round(0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25);
                } elseif (isset($scopusMap[$norm])) {
                    $sp = $scopusMap[$norm];
                    $arr['source'] = 'scopus';
                    $arr['source_id'] = $sp->id;
                    $arr['category'] = 'Jurnal Internasional';
                    $arr['quartile'] = $sp->quartile;
                    $arr['author_role'] = $sp->author_role;
                    $arr['author_order'] = $sp->author_order;
                    $arr['total_authors'] = $sp->total_authors;
                    $arr['is_corresponding'] = (bool)$sp->is_corresponding;
                    $arr['is_corresponding_confirmed'] = (bool)$sp->is_corresponding_confirmed;
                    $arr['is_hyperauthor'] = (bool)$sp->is_hyperauthor;
                    $arr['awarded_points'] = $sp->awarded_points ?: $arr['awarded_points'];
                } elseif ($doc->category === 'Google Scholar') {
                    $arr['category'] = 'Jurnal Nasional';
                    $arr['source'] = 'scholar';
                    $arr['citations'] = (int)($arr['citations'] ?? 0);
                }

                return $arr;
            });

            $existingManualTitles = collect($manualDocs)->pluck('title')->map(fn($t) => strtolower(preg_replace('/[^a-z0-9]/', '', $t)))->filter()->toArray();

            $scopusDocs = $scopusPubs->filter(function($pub) use ($existingManualTitles) {
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $pub->title));
                return !in_array($norm, $existingManualTitles);
            })->map(function($pub) {
                return [
                    'id' => 'scopus_' . $pub->id,
                    'title' => $pub->title,
                    'category' => 'Jurnal Internasional',
                    'published_at' => $pub->year ? ($pub->year . '-01-01') : null,
                    'quartile' => $pub->quartile,
                    'author_role' => $pub->author_role,
                    'author_order' => $pub->author_order,
                    'total_authors' => $pub->total_authors,
                    'is_corresponding' => (bool)$pub->is_corresponding,
                    'is_corresponding_confirmed' => (bool)$pub->is_corresponding_confirmed,
                    'is_hyperauthor' => (bool)$pub->is_hyperauthor,
                    'status' => 'Approved',
                    'awarded_points' => $pub->awarded_points ?: 0,
                    'file_url' => '-',
                    'source' => 'scopus',
                    'source_id' => $pub->id,
                ];
            })->values();

            $scholarDocs = $scholarPubs->filter(function($pub) use ($existingManualTitles) {
                $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $pub->title));
                return !in_array($norm, $existingManualTitles);
            })->map(function($pub) {
                $citations = (int)($pub->citations ?? 0);
                $awardedPoints = (int)round(0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25);
                return [
                    'id' => 'scholar_' . $pub->id,
                    'title' => $pub->title,
                    'category' => 'Jurnal Nasional',
                    'published_at' => $pub->year ? ($pub->year . '-01-01') : null,
                    'citations' => $citations,
                    'quartile' => null,
                    'author_role' => null,
                    'author_order' => null,
                    'total_authors' => null,
                    'is_corresponding' => (bool)$pub->is_corresponding,
                    'is_corresponding_confirmed' => (bool)$pub->is_corresponding_confirmed,
                    'is_hyperauthor' => false,
                    'status' => 'Approved',
                    'awarded_points' => $awardedPoints,
                    'file_url' => '-',
                    'source' => 'scholar',
                    'source_id' => $pub->id,
                ];
            })->values();

            return collect($manualDocs)->concat($scopusDocs)->concat($scholarDocs)->sortByDesc('published_at')->values();
        };

        if (\Illuminate\Support\Facades\Cache::supportsTags()) {
            \Illuminate\Support\Facades\Cache::tags(["user_documents_{$id}", 'documents'])->flush();
            $allDocs = \Illuminate\Support\Facades\Cache::tags(["user_documents_{$id}", 'documents'])->remember($cacheKey, 3600, $fetchData);
        } else {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            $allDocs = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, $fetchData);
        }

        return response()->json([
            'success' => true,
            'documents' => $allDocs,
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

        if ($request->has('sinta_rank')) {
            $doc->sinta_rank = $request->sinta_rank;
            $doc->is_sinta_confirmed = true;
        }

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
        if (str_starts_with($id, 'scopus_')) {
            $realId = str_replace('scopus_', '', $id);
            $pub = \App\Models\ScopusPublication::findOrFail($realId);
            $userId = $pub->user_id;
            $docTitle = $pub->title;
            $pub->delete();

            $user = User::find($userId);
            if ($user) {
                $user->recalculateKpiPoints();
            }

            if (Cache::supportsTags()) {
                Cache::tags(["user_documents_{$userId}", 'documents', 'admin_documents', 'stats'])->flush();
            } else {
                Cache::forget("user_documents_{$userId}");
                Cache::flush();
            }

            \App\Models\ActivityLog::log($userId, 'Delete Document', 'Dosen menghapus dokumen Scopus: ' . $docTitle);

            return response()->json([
                'success' => true,
                'message' => 'Publikasi Scopus berhasil dihapus.',
            ]);
        }

        if (str_starts_with($id, 'scholar_')) {
            $realId = str_replace('scholar_', '', $id);
            $pub = \App\Models\ScholarPublication::findOrFail($realId);
            $userId = $pub->user_id;
            $docTitle = $pub->title;
            $pub->delete();

            $user = User::find($userId);
            if ($user) {
                $user->recalculateKpiPoints();
            }

            if (Cache::supportsTags()) {
                Cache::tags(["user_documents_{$userId}", 'documents', 'admin_documents', 'stats'])->flush();
            } else {
                Cache::forget("user_documents_{$userId}");
                Cache::flush();
            }

            \App\Models\ActivityLog::log($userId, 'Delete Document', 'Dosen menghapus dokumen Scholar: ' . $docTitle);

            return response()->json([
                'success' => true,
                'message' => 'Publikasi Google Scholar berhasil dihapus.',
            ]);
        }

        if (str_starts_with($id, 'openalex_')) {
            $realId = str_replace('openalex_', '', $id);
            $pub = \App\Models\OpenalexPublication::findOrFail($realId);
            $userId = $pub->user_id;
            $docTitle = $pub->title;
            $pub->delete();

            $user = User::find($userId);
            if ($user) {
                $user->recalculateKpiPoints();
            }

            if (Cache::supportsTags()) {
                Cache::tags(["user_documents_{$userId}", 'documents', 'admin_documents', 'stats'])->flush();
            } else {
                Cache::forget("user_documents_{$userId}");
                Cache::flush();
            }

            \App\Models\ActivityLog::log($userId, 'Delete Document', 'Dosen menghapus dokumen OpenAlex: ' . $docTitle);

            return response()->json([
                'success' => true,
                'message' => 'Publikasi OpenAlex berhasil dihapus.',
            ]);
        }

        $doc = Document::findOrFail($id);

        // Lock: cannot delete if already verified or approved
        if (in_array($doc->status, ['Verified by Fakultas', 'Approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen yang sudah diverifikasi/disetujui tidak dapat dihapus.',
            ], 403);
        }

        // Delete stored file
        if ($doc->file_url && $doc->file_url !== '-' && $doc->file_url !== '') {
            $relativePath = str_replace('/storage/', '', $doc->file_url);
            Storage::disk('public')->delete($relativePath);
        }

        $docTitle = $doc->title;
        $userId   = $doc->user_id;

        $doc->delete();

        // Recalculate total kpi points
        $user = User::find($userId);
        if ($user) {
            $user->recalculateKpiPoints();
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

    public function getHistory(Request $request, $id)
    {
        $type = $request->query('type');
        if ($type === 'penelitian') {
            $penelitian = \App\Models\Penelitian::findOrFail($id);
            $history = $penelitian->history()->with('user:id,name,role')->get();
        } elseif (is_string($id) && (str_starts_with($id, 'scopus_') || str_starts_with($id, 'scholar_'))) {
            $history = [
                [
                    'id' => 1,
                    'action' => 'Sinkronisasi Otomatis API',
                    'notes' => 'Dokumen disinkronkan secara otomatis dari API eksternal.',
                    'created_at' => now()->toDateTimeString(),
                    'user' => null
                ]
            ];
        } else {
            $doc = Document::findOrFail($id);
            $history = $doc->history()->with('user:id,name,role')->get();
        }
        return response()->json([
            'success' => true,
            'history' => $history
        ]);
    }

    public function updateCorresponding(Request $request, $id)
    {
        $request->validate([
            'is_corresponding' => 'required|boolean'
        ]);

        $doc = Document::findOrFail($id);
        $user = $doc->user;

        \Illuminate\Support\Facades\DB::transaction(function () use ($doc, $user, $request) {
            $doc->is_corresponding = $request->is_corresponding;
            $doc->is_corresponding_confirmed = true;
            $doc->awarded_points = round($doc->calculatePoints());
            $doc->save();

            $user->recalculateKpiPoints();
        });

        if (\Illuminate\Support\Facades\Cache::supportsTags()) {
            \Illuminate\Support\Facades\Cache::tags(["user_documents_{$doc->user_id}", 'documents', 'admin_documents', 'stats'])->flush();
        } else {
            \Illuminate\Support\Facades\Cache::forget("user_documents_{$doc->user_id}");
            \Illuminate\Support\Facades\Cache::flush();
        }

        return response()->json([
            'success' => true,
            'message' => 'Corresponding status updated successfully',
            'document' => $doc
        ]);
    }

    public function updateCorrespondingScholar(Request $request, $id)
    {
        $request->validate([
            'is_corresponding' => 'required|boolean'
        ]);

        $pub = \App\Models\ScholarPublication::findOrFail($id);
        $user = $pub->user;

        \Illuminate\Support\Facades\DB::transaction(function () use ($pub, $user, $request) {
            $pub->is_corresponding = $request->is_corresponding;
            $pub->is_corresponding_confirmed = true;
            $pub->save();

            $user->recalculateKpiPoints();
        });

        if (\Illuminate\Support\Facades\Cache::supportsTags()) {
            \Illuminate\Support\Facades\Cache::tags(["user_documents_{$user->id}", 'documents', 'admin_documents', 'stats'])->flush();
        } else {
            \Illuminate\Support\Facades\Cache::forget("user_documents_{$user->id}");
            \Illuminate\Support\Facades\Cache::flush();
        }

        return response()->json([
            'success' => true,
            'message' => 'Corresponding status updated successfully',
            'publication' => $pub
        ]);
    }
}


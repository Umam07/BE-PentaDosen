<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Document;
use App\Models\PointWeight;
use App\Models\ScholarPublication;
use App\Models\ScopusPublication;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DocumentController extends Controller
{
    // KPI Active period
    private $kpiPeriodStart = '2025-01-01';
    private $kpiPeriodEnd   = '2027-12-31';
    private $kpiPeriodLabel = '2025-2027';

    public function upload(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'title' => 'required',
            'category' => 'required',
            'published_at' => 'required|date',
            'doc_type' => 'required|in:kpi,arsip',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $path = $request->file('file')->store('uploads', 'public');
        $fileUrl = Storage::url($path);

        $publishedAt = Carbon::parse($request->published_at);
        $docType = $request->doc_type;

        // Determine KPI status
        $isKpi = false;
        $accreditationPeriod = null;

        if ($docType === 'kpi') {
            $periodStart = Carbon::parse($this->kpiPeriodStart);
            $periodEnd = Carbon::parse($this->kpiPeriodEnd);
            $isKpi = $publishedAt->between($periodStart, $periodEnd);
            $accreditationPeriod = $this->kpiPeriodLabel;
        }
        // If docType is 'arsip', isKpi stays false, no period

        // Check auto-verification: match title with Scholar/Scopus publications
        $autoVerified = false;
        $awardedPoints = 0;

        if ($isKpi) {
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
            }
        }

        $doc = DB::transaction(function () use ($request, $fileUrl, $publishedAt, $isKpi, $accreditationPeriod, $autoVerified, $awardedPoints) {
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
            ]);

            // If auto-verified, add points to user's total KPI
            if ($autoVerified && $awardedPoints > 0) {
                $doc->user->increment('total_kpi_points', $awardedPoints);
            }

            return $doc;
        });

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
        $docs = Document::where('user_id', $id)->orderBy('created_at', 'desc')->get();
        return response()->json(['documents' => $docs]);
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
                'label' => $this->kpiPeriodLabel,
                'start' => $this->kpiPeriodStart,
                'end' => $this->kpiPeriodEnd,
            ],
        ]);
    }
}

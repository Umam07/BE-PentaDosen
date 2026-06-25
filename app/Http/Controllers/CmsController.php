<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\SystemSetting;
use App\Models\PointWeight;
use App\Models\Announcement;
use App\Models\Faq;
use App\Models\DocumentTemplate;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CmsController extends Controller
{
    // ==========================================
    // 1. SETTINGS & KPI PERIOD
    // ==========================================
    public function getSettings()
    {
        return response()->json([
            'kpi_period_start' => SystemSetting::getValue('kpi_period_start', '2025-01-01'),
            'kpi_period_end' => SystemSetting::getValue('kpi_period_end', '2027-12-31'),
            'kpi_period_label' => SystemSetting::getValue('kpi_period_label', '2025-2027'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'kpi_period_start' => 'required|date',
            'kpi_period_end' => 'required|date|after_or_equal:kpi_period_start',
            'kpi_period_label' => 'required|string',
        ]);

        SystemSetting::setValue('kpi_period_start', $request->kpi_period_start);
        SystemSetting::setValue('kpi_period_end', $request->kpi_period_end);
        SystemSetting::setValue('kpi_period_label', $request->kpi_period_label);

        return response()->json(['success' => true, 'message' => 'Periode KPI berhasil diperbarui.']);
    }

    // ==========================================
    // 2. POINT WEIGHTS (KPI MASTER DATA)
    // ==========================================
    public function getWeights()
    {
        $weights = PointWeight::all();
        return response()->json(['weights' => $weights]);
    }

    public function updateWeights(Request $request)
    {
        $request->validate([
            'weights' => 'required|array',
            'weights.*.category' => 'required|string',
            'weights.*.weight_value' => 'required|integer|min:0',
        ]);

        foreach ($request->weights as $w) {
            PointWeight::updateOrCreate(
                ['category' => $w['category']],
                ['weight_value' => $w['weight_value']]
            );
        }

        return response()->json(['success' => true, 'message' => 'Bobot poin KPI berhasil diperbarui.']);
    }

    public function storeWeight(Request $request)
    {
        $request->validate([
            'category' => 'required|string|unique:point_weights,category',
            'weight_value' => 'required|integer|min:0',
        ]);

        $weight = PointWeight::create([
            'category' => $request->category,
            'weight_value' => $request->weight_value,
        ]);

        return response()->json(['success' => true, 'weight' => $weight, 'message' => 'Kategori baru berhasil ditambahkan.']);
    }

    public function destroyWeight($category)
    {
        $weight = PointWeight::where('category', $category)->first();
        if ($weight) {
            $weight->delete();
            return response()->json(['success' => true, 'message' => 'Kategori berhasil dihapus.']);
        }
        return response()->json(['success' => false, 'message' => 'Kategori tidak ditemukan.'], 404);
    }

    // ==========================================
    // 3. ANNOUNCEMENTS
    // ==========================================
    public function indexAnnouncements()
    {
        $announcements = Announcement::orderBy('created_at', 'desc')->get();
        return response()->json(['announcements' => $announcements]);
    }

    public function getActiveAnnouncements()
    {
        $now = Carbon::now();
        $announcements = Announcement::where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>=', $now);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['announcements' => $announcements]);
    }

    public function storeAnnouncement(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date',
            'created_by' => 'nullable|exists:users,id',
        ]);

        $announcement = Announcement::create([
            'title' => $request->title,
            'content' => $request->input('content'),
            'is_active' => $request->is_active ?? true,
            'expires_at' => $request->expires_at,
            'created_by' => $request->created_by,
        ]);

        return response()->json(['success' => true, 'announcement' => $announcement, 'message' => 'Pengumuman berhasil diterbitkan.']);
    }

    public function updateAnnouncement(Request $request, $id)
    {
        $announcement = Announcement::findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date',
        ]);

        $announcement->update($request->only(['title', 'content', 'is_active', 'expires_at']));

        return response()->json(['success' => true, 'announcement' => $announcement, 'message' => 'Pengumuman berhasil diperbarui.']);
    }

    public function destroyAnnouncement($id)
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->delete();

        return response()->json(['success' => true, 'message' => 'Pengumuman berhasil dihapus.']);
    }

    // ==========================================
    // 4. FAQS
    // ==========================================
    public function indexFaqs()
    {
        $faqs = Faq::orderBy('order_index', 'asc')->orderBy('created_at', 'desc')->get();
        return response()->json(['faqs' => $faqs]);
    }

    public function storeFaq(Request $request)
    {
        $request->validate([
            'question' => 'required|string',
            'answer' => 'required|string',
            'category' => 'required|string',
            'order_index' => 'integer',
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $fileUrl = null;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('faqs', 'public');
            $fileUrl = Storage::url($path);
        }

        $faq = Faq::create([
            'question' => $request->question,
            'answer' => $request->answer,
            'category' => $request->category,
            'order_index' => $request->order_index ?? 0,
            'file_url' => $fileUrl,
        ]);

        return response()->json(['success' => true, 'faq' => $faq, 'message' => 'FAQ berhasil ditambahkan.']);
    }

    public function updateFaq(Request $request, $id)
    {
        $faq = Faq::findOrFail($id);

        $request->validate([
            'question' => 'required|string',
            'answer' => 'required|string',
            'category' => 'required|string',
            'order_index' => 'integer',
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $faq->question = $request->question;
        $faq->answer = $request->answer;
        $faq->category = $request->category;
        $faq->order_index = $request->order_index ?? 0;

        // Check if PDF removal is requested
        if ($request->input('remove_file') === 'true' || $request->input('remove_file') === true) {
            if ($faq->file_url && $faq->file_url !== '-') {
                $relativePath = str_replace('/storage/', '', $faq->file_url);
                Storage::disk('public')->delete($relativePath);
            }
            $faq->file_url = null;
        }

        // Handle new file upload
        if ($request->hasFile('file')) {
            if ($faq->file_url && $faq->file_url !== '-') {
                $relativePath = str_replace('/storage/', '', $faq->file_url);
                Storage::disk('public')->delete($relativePath);
            }
            $path = $request->file('file')->store('faqs', 'public');
            $faq->file_url = Storage::url($path);
        }

        $faq->save();

        return response()->json(['success' => true, 'faq' => $faq, 'message' => 'FAQ berhasil diperbarui.']);
    }

    public function destroyFaq($id)
    {
        $faq = Faq::findOrFail($id);

        // Delete associated file if exists
        if ($faq->file_url && $faq->file_url !== '-') {
            $relativePath = str_replace('/storage/', '', $faq->file_url);
            Storage::disk('public')->delete($relativePath);
        }

        $faq->delete();

        return response()->json(['success' => true, 'message' => 'FAQ berhasil dihapus.']);
    }

    // ==========================================
    // 5. DOCUMENT TEMPLATES
    // ==========================================
    public function indexTemplates()
    {
        $templates = DocumentTemplate::all();
        return response()->json(['templates' => $templates]);
    }

    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:research,publication,hki,buku',
            'file' => 'required|file|mimes:xlsx,xls|max:5120', // Max 5MB
        ]);

        $type = $request->type;
        $file = $request->file('file');
        
        $path = $file->store('templates', 'public');
        $fileUrl = Storage::url($path);

        $template = DocumentTemplate::updateOrCreate(
            ['type' => $type],
            [
                'file_name' => $file->getClientOriginalName(),
                'file_url' => $fileUrl,
                'uploaded_at' => Carbon::now()
            ]
        );

        return response()->json([
            'success' => true, 
            'template' => $template, 
            'message' => 'Template berhasil diunggah.'
        ]);
    }

    // ==========================================
    // 6. USER & ROLES MANAGEMENT
    // ==========================================
    public function getUsers(Request $request)
    {
        $search = $request->query('search');
        $roleFilter = $request->query('role');
        $perPage = $request->query('per_page', 20);

        $users = User::when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('nidn', 'like', "%{$search}%");
                });
            })
            ->when($roleFilter, function ($query) use ($roleFilter) {
                $query->where('role', $roleFilter);
            })
            ->orderBy('name', 'asc')
            ->paginate($perPage);

        return response()->json($users);
    }

    public function assignRole(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'role' => 'required|string|in:dosen,staf,admin lppm,admin fakultas,super admin,reviewer',
            'fakultas' => 'nullable|string',
            'program_studi' => 'nullable|string',
        ]);

        $user->role = $request->role;
        
        if ($request->role === 'admin fakultas' || $request->role === 'dosen') {
            $user->fakultas = $request->fakultas;
            $user->program_studi = $request->program_studi;
        } else {
            // Non-faculty users shouldn't be tied to specific faculties in their main admin access (optional)
            if ($request->has('fakultas')) {
                $user->fakultas = $request->fakultas;
            }
            if ($request->has('program_studi')) {
                $user->program_studi = $request->program_studi;
            }
        }

        $user->save();

        return response()->json([
            'success' => true, 
            'user' => $user, 
            'message' => "Hak akses {$user->name} berhasil diperbarui menjadi {$request->role}."
        ]);
    }
}

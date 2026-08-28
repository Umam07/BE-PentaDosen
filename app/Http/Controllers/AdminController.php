<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Document;
use App\Models\User;
use App\Models\PointWeight;
use App\Models\Notification;
use App\Models\DocumentHistory;
use App\Models\ScopusPublication;
use App\Models\ScholarPublication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AdminController extends Controller
{
    public function getPendingDocuments(Request $request)
    {
        $role = $request->query('role');
        $userId = $request->query('user_id');

        $query = Document::with('user');

        if ($role === 'admin fakultas') {
            $query->where('status', 'Pending');
            $admin = User::find($userId);
            if ($admin && $admin->fakultas) {
                $query->whereHas('user', function ($q) use ($admin) {
                    $q->where('fakultas', $admin->fakultas);
                    if ($admin->program_studi) {
                        $cleanProdi = trim(preg_replace('/\s*\(.*?\)\s*/', '', $admin->program_studi));
                        $q->where(function ($sub) use ($admin, $cleanProdi) {
                            $sub->where('program_studi', $admin->program_studi)
                                ->orWhere('program_studi', 'LIKE', '%' . $cleanProdi . '%')
                                ->orWhereNull('program_studi')
                                ->orWhere('program_studi', '');
                        });
                    }
                });
            }
        } elseif ($role === 'admin penelitian') {
            $query->where('status', 'Verified by Fakultas');
        } else {
            $query->where('status', 'Pending');
        }

        $rawDocs = $query->orderBy('created_at', 'asc')->get();

        $grouped = [];
        foreach ($rawDocs as $doc) {
            $key = strtolower(trim($doc->title)) . '|' . ($doc->file_url ?: $doc->category);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $doc;
            }
        }

        $docs = collect($grouped)->map(function ($doc) {
            // Find all sibling co-authors across the entire university linked to this paper
            $siblings = Document::with('user')
                ->where('title', $doc->title)
                ->where('file_url', $doc->file_url)
                ->where('id', '!=', $doc->id)
                ->get()
                ->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'user_id' => $s->user_id,
                        'user_name' => $s->user ? $s->user->name : '',
                        'fakultas' => $s->user ? $s->user->fakultas : '',
                        'program_studi' => $s->user ? $s->user->program_studi : '',
                        'author_role' => $s->author_role,
                        'author_order' => $s->author_order,
                    ];
                })
                ->values()
                ->toArray();

            return array_merge($doc->toArray(), [
                'user_name' => $doc->user ? $doc->user->name : '',
                'fakultas' => $doc->user ? $doc->user->fakultas : '',
                'program_studi' => $doc->user ? $doc->user->program_studi : '',
                'co_authors_list' => $siblings,
                'co_authors_count' => count($siblings)
            ]);
        })->values();

        return response()->json(['documents' => $docs]);
    }

    public function getAllDocuments(Request $request)
    {
        $role = $request->query('role');
        $userId = $request->query('user_id');
        $cacheKey = "admin_all_documents_{$role}_{$userId}";

        $fetchData = function () use ($role, $userId) {
            $adminFakultas = null;
            $adminProdi = null;
            if ($role === 'admin fakultas') {
                $admin = User::find($userId);
                if ($admin && $admin->fakultas) {
                    $adminFakultas = $admin->fakultas;
                    $adminProdi = $admin->program_studi;
                }
            }

            // 1. Fetch manual documents
            $query = Document::with('user');
            if ($adminFakultas) {
                $query->whereHas('user', function ($q) use ($adminFakultas, $adminProdi) {
                    $q->where('fakultas', $adminFakultas);
                    if ($adminProdi) {
                        $cleanProdi = trim(preg_replace('/\s*\(.*?\)\s*/', '', $adminProdi));
                        $q->where(function ($sub) use ($adminProdi, $cleanProdi) {
                            $sub->where('program_studi', $adminProdi)
                                ->orWhere('program_studi', 'LIKE', '%' . $cleanProdi . '%')
                                ->orWhereNull('program_studi')
                                ->orWhere('program_studi', '');
                        });
                    }
                });
            }

            $manualDocs = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($doc) {
                    return array_merge($doc->toArray(), [
                        'user_name' => $doc->user ? $doc->user->name : '',
                        'fakultas' => $doc->user ? $doc->user->fakultas : '',
                    ]);
                });

            // Map user_id -> set of normalized titles existing in Document table to prevent duplicates
            $existingTitles = [];
            foreach ($manualDocs as $d) {
                $uid = $d['user_id'];
                $normT = preg_replace('/[^a-z0-9]/', '', strtolower($d['title'] ?? ''));
                if ($normT) {
                    $existingTitles[$uid][$normT] = true;
                }
            }

            $kpiPeriodStart = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_start', '2026-01-01'));
            $kpiPeriodEnd   = \Carbon\Carbon::parse(\App\Models\SystemSetting::getValue('kpi_period_end', '2026-12-31'));
            $kpiPeriodLabel = \App\Models\SystemSetting::getValue('kpi_period_label', '2026');

            // 2. Fetch Scopus Publications
            $scopusQuery = ScopusPublication::with('user');
            if ($adminFakultas) {
                $scopusQuery->whereHas('user', function ($q) use ($adminFakultas, $adminProdi) {
                    $q->where('fakultas', $adminFakultas);
                    if ($adminProdi) {
                        $cleanProdi = trim(preg_replace('/\s*\(.*?\)\s*/', '', $adminProdi));
                        $q->where(function ($sub) use ($adminProdi, $cleanProdi) {
                            $sub->where('program_studi', $adminProdi)
                                ->orWhere('program_studi', 'LIKE', '%' . $cleanProdi . '%')
                                ->orWhereNull('program_studi')
                                ->orWhere('program_studi', '');
                        });
                    }
                });
            }

            $scopusDocs = $scopusQuery->get()->filter(function ($pub) use (&$existingTitles) {
                $normT = preg_replace('/[^a-z0-9]/', '', strtolower($pub->title ?? ''));
                if ($normT && isset($existingTitles[$pub->user_id][$normT])) {
                    return false;
                }
                if ($normT) {
                    $existingTitles[$pub->user_id][$normT] = true;
                }
                return true;
            })->map(function ($pub) use ($kpiPeriodStart, $kpiPeriodEnd, $kpiPeriodLabel) {
                $publishedAt = $pub->year ? ($pub->year . '-01-01') : null;
                $isKpi = false;
                if ($pub->year) {
                    $dt = \Carbon\Carbon::createFromDate((int)$pub->year, 1, 1);
                    $isKpi = $dt->between($kpiPeriodStart, $kpiPeriodEnd);
                }

                return [
                    'id' => 'scopus_' . $pub->id,
                    'user_id' => $pub->user_id,
                    'title' => $pub->title,
                    'category' => 'Jurnal Internasional',
                    'file_url' => '-',
                    'published_at' => $publishedAt,
                    'status' => 'Approved',
                    'awarded_points' => (float)($pub->awarded_points ?: 0),
                    'citations' => (int)($pub->citations ?? 0),
                    'is_kpi_counted' => $isKpi,
                    'accreditation_period' => $isKpi ? $kpiPeriodLabel : null,
                    'user_name' => $pub->user ? $pub->user->name : '',
                    'fakultas' => $pub->user ? $pub->user->fakultas : '',
                    'created_at' => $pub->created_at ? $pub->created_at->toDateTimeString() : ($publishedAt ? $publishedAt . ' 00:00:00' : null),
                    'source' => 'scopus',
                ];
            });

            // 3. Fetch Scholar Publications
            $scholarQuery = ScholarPublication::with('user');
            if ($adminFakultas) {
                $scholarQuery->whereHas('user', function ($q) use ($adminFakultas, $adminProdi) {
                    $q->where('fakultas', $adminFakultas);
                    if ($adminProdi) {
                        $cleanProdi = trim(preg_replace('/\s*\(.*?\)\s*/', '', $adminProdi));
                        $q->where(function ($sub) use ($adminProdi, $cleanProdi) {
                            $sub->where('program_studi', $adminProdi)
                                ->orWhere('program_studi', 'LIKE', '%' . $cleanProdi . '%')
                                ->orWhereNull('program_studi')
                                ->orWhere('program_studi', '');
                        });
                    }
                });
            }

            $scholarDocs = $scholarQuery->get()->filter(function ($pub) use (&$existingTitles) {
                $normT = preg_replace('/[^a-z0-9]/', '', strtolower($pub->title ?? ''));
                if ($normT && isset($existingTitles[$pub->user_id][$normT])) {
                    return false;
                }
                if ($normT) {
                    $existingTitles[$pub->user_id][$normT] = true;
                }
                return true;
            })->map(function ($pub) use ($kpiPeriodStart, $kpiPeriodEnd, $kpiPeriodLabel) {
                $publishedAt = $pub->year ? ($pub->year . '-01-01') : null;
                $isKpi = false;
                if ($pub->year) {
                    $dt = \Carbon\Carbon::createFromDate((int)$pub->year, 1, 1);
                    $isKpi = $dt->between($kpiPeriodStart, $kpiPeriodEnd);
                }

                $citations = (int)($pub->citations ?? 0);
                $awardedPoints = round(0.5 + ($citations > 0 ? 0.5 : 0) + min($citations, 500) * 0.25);

                $role = $pub->author_role;
                $order = $pub->author_order;
                $total = $pub->total_authors;

                if (empty($role) || empty($total)) {
                    $userName = $pub->user ? $pub->user->name : '';
                    $parsed = \App\Http\Controllers\ScholarController::parseScholarAuthors($userName, $pub->authors);
                    $role = $role ?: $parsed['author_role'];
                    $order = $order ?: $parsed['author_order'];
                    $total = $total ?: $parsed['total_authors'];
                }

                return [
                    'id' => 'scholar_' . $pub->id,
                    'user_id' => $pub->user_id,
                    'title' => $pub->title,
                    'authors' => $pub->authors,
                    'author_role' => $role,
                    'author_order' => $order,
                    'total_authors' => $total,
                    'journal' => $pub->journal,
                    'category' => 'Jurnal Nasional',
                    'file_url' => '-',
                    'published_at' => $publishedAt,
                    'status' => 'Approved',
                    'awarded_points' => (float)$awardedPoints,
                    'citations' => $citations,
                    'is_kpi_counted' => $isKpi,
                    'accreditation_period' => $isKpi ? $kpiPeriodLabel : null,
                    'user_name' => $pub->user ? $pub->user->name : '',
                    'fakultas' => $pub->user ? $pub->user->fakultas : '',
                    'created_at' => $pub->created_at ? $pub->created_at->toDateTimeString() : ($publishedAt ? $publishedAt . ' 00:00:00' : null),
                    'source' => 'scholar',
                ];
            });

            return collect($manualDocs)
                ->concat($scopusDocs)
                ->concat($scholarDocs)
                ->sortByDesc('created_at')
                ->values();
        };

        if (Cache::supportsTags()) {
            $docs = Cache::tags(['admin_documents', 'documents'])->remember($cacheKey, 3600, $fetchData);
        } else {
            $docs = Cache::remember($cacheKey, 3600, $fetchData);
        }

        return response()->json(['documents' => $docs]);
    }

    public function getAllLecturers(Request $request)
    {
        $role = $request->query('role');
        $userId = $request->query('user_id');
        $cacheKey = "admin_all_lecturers_{$role}_{$userId}";

        $fetchData = function () use ($role, $userId) {
            $query = User::with(['scholarData', 'scopusData', 'publications', 'scopusPublications', 'documents', 'penelitian'])
                ->where('role', 'dosen');

            if ($role === 'admin fakultas') {
                $admin = User::find($userId);
                if ($admin && $admin->fakultas) {
                    $query->where('fakultas', $admin->fakultas);
                    if ($admin->program_studi) {
                        $cleanProdi = trim(preg_replace('/\s*\(.*?\)\s*/', '', $admin->program_studi));
                        $query->where(function ($sub) use ($admin, $cleanProdi) {
                            $sub->where('program_studi', $admin->program_studi)
                                ->orWhere('program_studi', 'LIKE', '%' . $cleanProdi . '%')
                                ->orWhereNull('program_studi')
                                ->orWhere('program_studi', '');
                        });
                    }
                }
            }

            return $query->orderBy('name', 'asc')
                ->get()
                ->map(function ($u) {
                    $publications = $u->publications;
                    $scopusPublications = $u->scopusPublications;

                    $normalizeT = function($title) {
                        return preg_replace('/[^a-z0-9]/', '', strtolower($title));
                    };

                    $crossTitles = [];
                    foreach ($publications as $pub) {
                        $normPub = $normalizeT($pub->title);
                        foreach ($scopusPublications as $scop) {
                            if ($normalizeT($scop->title) === $normPub) {
                                $crossTitles[] = $normPub;
                                break;
                            }
                        }
                    }
                    $crossTitlesSet = array_unique($crossTitles);

                    $extCross = 0;
                    foreach ($scopusPublications as $scop) {
                        $normT = $normalizeT($scop->title);
                        if (in_array($normT, $crossTitlesSet)) {
                            $extCross += (float)$scop->awarded_points;
                        }
                    }

                    $extScopus = 0;
                    foreach ($scopusPublications as $scop) {
                        $normT = $normalizeT($scop->title);
                        if (!in_array($normT, $crossTitlesSet)) {
                            $extScopus += (float)$scop->awarded_points;
                        }
                    }

                    $extScholar = 0;
                    foreach ($publications as $pub) {
                        $normT = $normalizeT($pub->title);
                        if (!in_array($normT, $crossTitlesSet)) {
                            $citations = (int)($pub->citations ?? 0);
                            $docPoints = 0.5;
                            $citationBonus = $citations > 0 ? 0.5 : 0;
                            $citationPoints = min($citations, 500) * 0.25;
                            $extScholar += ($docPoints + $citationBonus + $citationPoints);
                        }
                    }

                    $poinExternal = round($extCross + $extScopus + $extScholar, 1);

                    // Poin Internal: Documents Approved + Penelitian Approved
                    $poinInternalDoc = $u->documents->filter(function($d) {
                        return $d->status === 'Approved';
                    })->sum('awarded_points');

                    $poinInternalPen = $u->penelitian->filter(function($p) {
                        return $p->status === 'Approved';
                    })->sum('awarded_points');

                    $poinInternal = round($poinInternalDoc + $poinInternalPen, 1);

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'nidn' => $u->nidn,
                        'penta_id' => $u->penta_id,
                        'fakultas' => $u->fakultas,
                        'program_studi' => $u->program_studi,
                        'scholar_id' => $u->scholar_id,
                        'scopus_id' => $u->scopus_id,
                        'total_kpi_points' => $u->total_kpi_points,
                        'poin_internal' => $poinInternal,
                        'poin_external' => $poinExternal,
                        'scholar_document_count' => count($publications),
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
        };

        if (Cache::supportsTags()) {
            $lecturers = Cache::tags(['lecturers'])->remember($cacheKey, 3600, $fetchData);
        } else {
            $lecturers = Cache::remember($cacheKey, 3600, $fetchData);
        }

        return response()->json(['lecturers' => $lecturers]);
    }

    public function verifyDocument(Request $request, $id)
    {
        $status = $request->status; // 'Approved' or 'Rejected'
        $role = $request->role; // 'admin penelitian' or 'admin fakultas'
        $doc = Document::findOrFail($id);

        if ($status === 'Approved') {
            if ($role === 'admin fakultas') {
                if ($doc->status !== 'Pending') {
                    return response()->json(['success' => false, 'message' => 'Dokumen sudah diverifikasi fakultas atau tahap admin.'], 400);
                }
                // If fakultas approves, move to next stage
                $doc->update(['status' => 'Verified by Fakultas']);

                \App\Models\DocumentHistory::create([
                    'document_id' => $doc->id,
                    'user_id' => $request->admin_id ?? $doc->user_id,
                    'action' => 'Diverifikasi Fakultas',
                    'notes' => null
                ]);

                // Also update sibling co-author documents
                $siblingDocs = Document::where('title', $doc->title)
                    ->where('file_url', $doc->file_url)
                    ->where('id', '!=', $doc->id)
                    ->get();
                foreach ($siblingDocs as $sibling) {
                    $sibling->update(['status' => 'Verified by Fakultas']);
                    \App\Models\DocumentHistory::create([
                        'document_id' => $sibling->id,
                        'user_id' => $request->admin_id ?? $doc->user_id,
                        'action' => 'Diverifikasi Fakultas',
                        'notes' => null
                    ]);
                    if (Cache::supportsTags()) {
                        Cache::tags(["user_documents_{$sibling->user_id}"])->flush();
                    } else {
                        Cache::forget("user_documents_{$sibling->user_id}");
                    }
                }
            } else {
                // Final Admin approval logic
                // Calculate points: use calculatePoints() for Jurnal Internasional & Nasional, otherwise table weight
                if ($doc->category === 'Jurnal Internasional' || $doc->category === 'Jurnal Nasional') {
                    $categoryPoints = round($doc->calculatePoints());
                } else {
                    $weight = PointWeight::where('category', $doc->category)->first();
                    $categoryPoints = $weight ? $weight->weight_value : 0;
                }

                // Enforce HKI Hak Cipta limit: max 2/tahun
                if ($doc->category === 'HKI Hak Cipta') {
                    $year = $doc->published_at ? \Carbon\Carbon::parse($doc->published_at)->year : null;
                    if ($year) {
                        $approvedCount = Document::where('user_id', $doc->user_id)
                            ->where('category', 'HKI Hak Cipta')
                            ->where('status', 'Approved')
                            ->whereYear('published_at', $year)
                            ->count();
                        if ($approvedCount >= 2) {
                            $categoryPoints = 0;
                        }
                    }
                }

                // Only award KPI points if document is within accreditation period
                $points = $doc->is_kpi_counted ? $categoryPoints : 0;

                DB::transaction(function () use ($doc, $status, $points, $request) {
                    $doc->update([
                        'status' => $status,
                        'awarded_points' => $points
                    ]);
                    
                    \App\Models\DocumentHistory::create([
                        'document_id' => $doc->id,
                        'user_id' => $request->admin_id ?? $doc->user_id,
                        'action' => 'Disetujui Admin Penelitian',
                        'notes' => null
                    ]);

                    if ($doc->user) {
                        $doc->user->recalculateKpiPoints();
                    }

                    // Also approve and calculate points for sibling co-author documents
                    $siblingDocs = Document::where('title', $doc->title)
                        ->where('file_url', $doc->file_url)
                        ->where('id', '!=', $doc->id)
                        ->get();

                    foreach ($siblingDocs as $sibling) {
                        $siblingPoints = 0;
                        if ($sibling->category === 'Jurnal Internasional' || $sibling->category === 'Jurnal Nasional') {
                            $siblingPoints = round($sibling->calculatePoints());
                        } else {
                            $siblingPoints = $points;
                        }
                        if (!$sibling->is_kpi_counted) {
                            $siblingPoints = 0;
                        }

                        $sibling->update([
                            'status' => 'Approved',
                            'awarded_points' => $siblingPoints
                        ]);

                        \App\Models\DocumentHistory::create([
                            'document_id' => $sibling->id,
                            'user_id' => $request->admin_id ?? $doc->user_id,
                            'action' => 'Disetujui Admin Penelitian',
                            'notes' => null
                        ]);

                        if ($sibling->user) {
                            $sibling->user->recalculateKpiPoints();
                        }

                        if (Cache::supportsTags()) {
                            Cache::tags(["user_documents_{$sibling->user_id}"])->flush();
                        } else {
                            Cache::forget("user_documents_{$sibling->user_id}");
                        }
                    }
                });
            }
        } else {
            // Either admin fakultas or admin lppm can reject
            $doc->update([
                'status' => $status,
                'catatan' => $request->catatan ?? null
            ]);
            
            \App\Models\DocumentHistory::create([
                'document_id' => $doc->id,
                'user_id' => $request->admin_id ?? $doc->user_id,
                'action' => 'Ditolak ' . ($request->role === 'admin fakultas' ? 'Fakultas' : 'Penelitian'),
                'notes' => $request->catatan ?? null
            ]);

            // Also reject siblings
            $siblingDocs = Document::where('title', $doc->title)
                ->where('file_url', $doc->file_url)
                ->where('id', '!=', $doc->id)
                ->get();
            foreach ($siblingDocs as $sibling) {
                $sibling->update([
                    'status' => $status,
                    'catatan' => $request->catatan ?? null
                ]);

                \App\Models\DocumentHistory::create([
                    'document_id' => $sibling->id,
                    'user_id' => $request->admin_id ?? $doc->user_id,
                    'action' => 'Ditolak ' . ($request->role === 'admin fakultas' ? 'Fakultas' : 'Penelitian'),
                    'notes' => $request->catatan ?? null
                ]);

                if (Cache::supportsTags()) {
                    Cache::tags(["user_documents_{$sibling->user_id}"])->flush();
                } else {
                    Cache::forget("user_documents_{$sibling->user_id}");
                }
            }
        }

        // Clear cache
        if (Cache::supportsTags()) {
            Cache::tags(["user_documents_{$doc->user_id}", 'documents', 'admin_documents', 'lecturers', 'stats', 'leaderboard'])->flush();
        } else {
            Cache::forget("user_documents_{$doc->user_id}");
            Cache::flush();
        }

        if ($request->admin_id) {
            \App\Models\ActivityLog::log($request->admin_id, 'Verifikasi Dokumen', "Mengubah status dokumen '{$doc->title}' menjadi {$status}");
        }

        // === NOTIFICATIONS ===
        $docTitle = $doc->title;
        $docOwnerUserId = $doc->user_id;

        $targetUserIds = [$docOwnerUserId];
        $siblingUserIds = Document::where('title', $doc->title)
            ->where('file_url', $doc->file_url)
            ->where('id', '!=', $doc->id)
            ->pluck('user_id')
            ->toArray();
        $targetUserIds = array_unique(array_merge($targetUserIds, $siblingUserIds));

        if ($status === 'Approved' && $role === 'admin fakultas') {
            // Admin Fakultas approved → notify all co-authors: verified by fakultas, waiting LPPM
            foreach ($targetUserIds as $uId) {
                Notification::send(
                    $uId,
                    'doc_verified_fakultas',
                    'Dokumen Diverifikasi Fakultas',
                    "Dokumen '{$docTitle}' telah diverifikasi oleh Admin Fakultas. Menunggu persetujuan LPPM.",
                    ['doc_id' => $doc->id]
                );
            }
            // Notify Admin Penelitian: document ready for their review
            $adminPenelitianList = User::where('role', 'admin penelitian')->get();
            foreach ($adminPenelitianList as $adminPenelitian) {
                Notification::send(
                    $adminPenelitian->id,
                    'doc_pending_lppm',
                    'Dokumen Siap Ditinjau LPPM',
                    "Dokumen '{$docTitle}' telah diverifikasi Fakultas dan menunggu persetujuan LPPM.",
                    ['doc_id' => $doc->id]
                );
            }
        } elseif ($status === 'Approved' && $role !== 'admin fakultas') {
            // Final Approval by LPPM → notify all co-authors
            foreach ($targetUserIds as $uId) {
                Notification::send(
                    $uId,
                    'doc_approved',
                    'Dokumen Disetujui ✓',
                    "Selamat! Dokumen '{$docTitle}' telah disetujui dan poin sudah ditambahkan ke akun Anda.",
                    ['doc_id' => $doc->id]
                );
            }
        } elseif ($status === 'Rejected') {
            $catatan = $request->catatan ? " Catatan: {$request->catatan}" : '';
            foreach ($targetUserIds as $uId) {
                Notification::send(
                    $uId,
                    'doc_rejected',
                    'Dokumen Ditolak',
                    "Dokumen '{$docTitle}' ditolak oleh admin.{$catatan} Silakan perbaiki dan ajukan ulang.",
                    ['doc_id' => $doc->id]
                );
            }
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

        // Clear caches
        if (Cache::supportsTags()) {
            Cache::tags(['lecturers', 'stats', 'leaderboard'])->flush();
        } else {
            Cache::flush();
        }

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

        // Clear caches
        if (Cache::supportsTags()) {
            Cache::tags(['lecturers', 'stats', 'leaderboard'])->flush();
        } else {
            Cache::flush();
        }

        return response()->json(['success' => true]);
    }
}

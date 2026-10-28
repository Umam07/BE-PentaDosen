<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UserController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ScopusController;
use App\Http\Controllers\PenelitianController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SintaController;

/*
|--------------------------------------------------------------------------
| 1. PUBLIC ROUTES (Unauthenticated / Throttled)
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [UserController::class, 'login']);
});

Route::middleware(['throttle:api'])->group(function () {
    // Public Dashboard, Statistics & Charts
    Route::get('/dashboard/stats', [UserController::class, 'getStats']);
    Route::get('/leaderboard', [UserController::class, 'leaderboard']);
    Route::get('/charts/prodi', [UserController::class, 'chartProdi']);
    Route::get('/charts/fakultas', [UserController::class, 'chartFakultas']);
    Route::get('/weights', [DocumentController::class, 'getWeights']);
    Route::get('/accreditation-periods', [DocumentController::class, 'getAccreditationPeriodsApi']);

    // Public CMS Views
    Route::get('/cms/settings', [CmsController::class, 'getSettings']);
    Route::get('/cms/weights', [CmsController::class, 'getWeights']);
    Route::get('/cms/announcements', [CmsController::class, 'indexAnnouncements']);
    Route::get('/dosen/announcements', [CmsController::class, 'getActiveAnnouncements']);
    Route::get('/cms/faqs', [CmsController::class, 'indexFaqs']);
    Route::get('/cms/templates', [CmsController::class, 'indexTemplates']);

    // Public Lecturer Directory & External ID Lookups
    Route::get('/lecturers/simple-list', [UserController::class, 'getLecturersList']);
    Route::get('/scholar/check/{scholar_id}', [ScholarController::class, 'checkId']);
    Route::get('/scopus/check/{scopus_id}', [ScopusController::class, 'checkId']);
    Route::get('/sinta/check-name', [SintaController::class, 'checkName']);
    Route::get('/sinta/dosen', [SintaController::class, 'getDosenList']);
});

/*
|--------------------------------------------------------------------------
| 2. AUTHENTICATED USER ROUTES (Sanctum Token or Session Cookie)
|--------------------------------------------------------------------------
*/
Route::middleware(['api.auth', 'throttle:api'])->group(function () {
    Route::post('/logout', [UserController::class, 'logout']);

    // User Profile
    Route::get('/users/{id}', [UserController::class, 'profile']);

    // External ID Linking & Syncs (Scoped to Owner or Admin)
    Route::post('/users/{id}/scholar', [ScholarController::class, 'updateScholarId']);
    Route::post('/users/{id}/sync', [ScholarController::class, 'sync'])->middleware('throttle:60,1');
    Route::post('/users/{id}/scopus', [ScopusController::class, 'updateScopusId']);
    Route::post('/users/{id}/sync-scopus', [ScopusController::class, 'sync'])->middleware('throttle:60,1');
    Route::post('/users/{id}/sync-sinta', [SintaController::class, 'syncUser']);

    // Scopus & Scholar Publications Metadata
    Route::put('/scopus-publications/{id}/quartile', [ScopusController::class, 'updateQuartile']);
    Route::put('/scopus-publications/{id}/corresponding', [ScopusController::class, 'updateCorresponding']);
    Route::put('/scholar-publications/{id}/corresponding', [DocumentController::class, 'updateCorrespondingScholar']);

    // Document Management
    Route::post('/documents', [DocumentController::class, 'upload']);
    Route::post('/documents/{id}/upload-pdf', [DocumentController::class, 'uploadPdf']);
    Route::post('/documents/{id}/link-penelitian', [DocumentController::class, 'linkToPenelitian']);
    Route::get('/users/{id}/documents', [DocumentController::class, 'getUserDocuments']);
    Route::get('/users/{id}/approved-penelitian', [DocumentController::class, 'getApprovedPenelitian']);
    Route::put('/documents/{id}', [DocumentController::class, 'update']);
    Route::put('/documents/{id}/corresponding', [DocumentController::class, 'updateCorresponding']);
    Route::post('/documents/bulk-correspondence', [DocumentController::class, 'bulkCorrespondence']);
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
    Route::get('/documents/{id}/history', [DocumentController::class, 'getHistory']);

    // Penelitian (Research) Management
    Route::post('/penelitian', [PenelitianController::class, 'store']);
    Route::get('/penelitian', [PenelitianController::class, 'index']);
    Route::post('/penelitian/{id}/upload-pdf', [PenelitianController::class, 'uploadPdf']);
    Route::put('/penelitian/{id}', [PenelitianController::class, 'update']);
    Route::delete('/penelitian/{id}', [PenelitianController::class, 'destroy']);

    // Notifications (User-scoped)
    Route::get('/notifications/{userId}', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/{userId}/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/{userId}/clear-all', [NotificationController::class, 'clearAll']);

    // Support Tickets (User-scoped)
    Route::post('/support-tickets', [SupportTicketController::class, 'store']);
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{id}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{id}/messages', [SupportTicketController::class, 'addMessage']);
});

/*
|--------------------------------------------------------------------------
| 3. ADMIN ROUTES (Admin Penelitian & Admin Fakultas)
|--------------------------------------------------------------------------
*/
Route::middleware(['api.auth', 'role:admin penelitian,admin fakultas', 'throttle:api'])->group(function () {
    // Document Verification & Admin Lists
    Route::get('/admin/documents', [AdminController::class, 'getPendingDocuments']);
    Route::get('/admin/documents/all', [AdminController::class, 'getAllDocuments']);
    Route::get('/admin/lecturers', [AdminController::class, 'getAllLecturers']);
    Route::post('/admin/documents/{id}/verify', [AdminController::class, 'verifyDocument']);
    Route::post('/penelitian/{id}/verify', [PenelitianController::class, 'verify']);

    // Activity Logs
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/admin/activity-logs', [ActivityLogController::class, 'store']);

    // CMS User Directory
    Route::get('/admin/cms/users', [CmsController::class, 'getUsers']);

    // Admin Support Tickets
    Route::get('/admin/support-tickets', [SupportTicketController::class, 'adminIndex']);
    Route::get('/admin/support-tickets/{id}', [SupportTicketController::class, 'adminShow']);
    Route::post('/admin/support-tickets/{id}/reply', [SupportTicketController::class, 'reply']);
    Route::patch('/admin/support-tickets/{id}/status', [SupportTicketController::class, 'updateStatus']);

    // Announcements Management
    Route::post('/cms/announcements', [CmsController::class, 'storeAnnouncement']);
    Route::put('/cms/announcements/{id}', [CmsController::class, 'updateAnnouncement']);
    Route::delete('/cms/announcements/{id}', [CmsController::class, 'destroyAnnouncement']);

    // FAQs Management
    Route::post('/cms/faqs', [CmsController::class, 'storeFaq']);
    Route::put('/cms/faqs/{id}', [CmsController::class, 'updateFaq']);
    Route::delete('/cms/faqs/{id}', [CmsController::class, 'destroyFaq']);
});

/*
|--------------------------------------------------------------------------
| 4. SUPERADMIN ROUTES (Admin Penelitian Only)
|--------------------------------------------------------------------------
*/
Route::middleware(['api.auth', 'role:admin penelitian', 'throttle:api'])->group(function () {
    Route::post('/admin/lecturers/bulk-scholar', [AdminController::class, 'bulkUpdateScholar']);
    Route::post('/admin/lecturers/bulk-scopus', [AdminController::class, 'bulkUpdateScopus']);
    Route::post('/admin/sinta/sync-all', [SintaController::class, 'syncAll']);

    // CMS Master Settings & Weights
    Route::post('/cms/settings', [CmsController::class, 'updateSettings']);
    Route::post('/cms/weights', [CmsController::class, 'updateWeights']);
    Route::post('/cms/weights/new', [CmsController::class, 'storeWeight']);
    Route::delete('/cms/weights/{category}', [CmsController::class, 'destroyWeight']);

    // Templates Upload
    Route::post('/cms/templates/upload', [CmsController::class, 'uploadTemplate']);

    // User Role Assignment
    Route::post('/admin/cms/users/{id}/assign-role', [CmsController::class, 'assignRole']);
});

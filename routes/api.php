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

// Auth Routes with strict rate limit
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/logout', [UserController::class, 'logout']);
});

// General API Routes with global rate limit
Route::middleware(['throttle:api'])->group(function () {
    Route::get('/users/{id}', [UserController::class, 'profile']);
    Route::post('/users/{id}/scholar', [ScholarController::class, 'updateScholarId']);
    Route::post('/users/{id}/sync', [ScholarController::class, 'sync'])->middleware('throttle:60,1');
    Route::get('/scholar/check/{scholar_id}', [ScholarController::class, 'checkId']);
    
    // SINTA Sync Routes
    Route::post('/users/{id}/sync-sinta', [SintaController::class, 'syncUser']);
    Route::post('/admin/sinta/sync-all', [SintaController::class, 'syncAll']);
    Route::get('/sinta/check-name', [SintaController::class, 'checkName']);
    Route::get('/sinta/dosen', [SintaController::class, 'getDosenList']);
    
    Route::post('/users/{id}/scopus', [ScopusController::class, 'updateScopusId']);
    Route::post('/users/{id}/sync-scopus', [ScopusController::class, 'sync'])->middleware('throttle:60,1');
    Route::get('/scopus/check/{scopus_id}', [ScopusController::class, 'checkId']);
    Route::put('/scopus-publications/{id}/quartile', [ScopusController::class, 'updateQuartile']);
    Route::put('/scopus-publications/{id}/corresponding', [ScopusController::class, 'updateCorresponding']);
    
    Route::post('/documents', [DocumentController::class, 'upload']);
    Route::post('/documents/{id}/upload-pdf', [DocumentController::class, 'uploadPdf']);
    Route::post('/documents/{id}/link-penelitian', [DocumentController::class, 'linkToPenelitian']);
    Route::get('/users/{id}/documents', [DocumentController::class, 'getUserDocuments']);
    Route::get('/users/{id}/approved-penelitian', [DocumentController::class, 'getApprovedPenelitian']);
    Route::put('/documents/{id}', [DocumentController::class, 'update']);
    Route::put('/documents/{id}/corresponding', [DocumentController::class, 'updateCorresponding']);
    Route::put('/scholar-publications/{id}/corresponding', [DocumentController::class, 'updateCorrespondingScholar']);
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
    Route::get('/documents/{id}/history', [DocumentController::class, 'getHistory']);
    
    Route::get('/admin/documents', [AdminController::class, 'getPendingDocuments']);
    Route::get('/admin/documents/all', [AdminController::class, 'getAllDocuments']);
    Route::get('/admin/lecturers', [AdminController::class, 'getAllLecturers']);
    Route::post('/admin/lecturers/bulk-scholar', [AdminController::class, 'bulkUpdateScholar']);
    Route::post('/admin/lecturers/bulk-scopus', [AdminController::class, 'bulkUpdateScopus']);
    Route::post('/admin/documents/{id}/verify', [AdminController::class, 'verifyDocument']);
    
    Route::post('/penelitian', [PenelitianController::class, 'store']);
    Route::get('/penelitian', [PenelitianController::class, 'index']);
    Route::post('/penelitian/{id}/verify', [PenelitianController::class, 'verify']);
    Route::post('/penelitian/{id}/upload-pdf', [PenelitianController::class, 'uploadPdf']);
    Route::put('/penelitian/{id}', [PenelitianController::class, 'update']);
    Route::delete('/penelitian/{id}', [PenelitianController::class, 'destroy']);

    
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/admin/activity-logs', [ActivityLogController::class, 'store']);

    // Notification Routes
    Route::get('/notifications/{userId}', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/{userId}/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/{userId}/clear-all', [NotificationController::class, 'clearAll']);
    
    Route::get('/leaderboard', [UserController::class, 'leaderboard']);
    Route::get('/charts/prodi', [UserController::class, 'chartProdi']);
    Route::get('/charts/fakultas', [UserController::class, 'chartFakultas']);
    Route::get('/weights', [DocumentController::class, 'getWeights']);
    Route::get('/accreditation-periods', [DocumentController::class, 'getAccreditationPeriodsApi']);
    Route::get('/dashboard/stats', [UserController::class, 'getStats']);

    // CMS Settings & Master Data
    Route::get('/cms/settings', [CmsController::class, 'getSettings']);
    Route::post('/cms/settings', [CmsController::class, 'updateSettings']);
    Route::get('/cms/weights', [CmsController::class, 'getWeights']);
    Route::post('/cms/weights', [CmsController::class, 'updateWeights']);
    Route::post('/cms/weights/new', [CmsController::class, 'storeWeight']);
    Route::delete('/cms/weights/{category}', [CmsController::class, 'destroyWeight']);

    // CMS Announcements
    Route::get('/cms/announcements', [CmsController::class, 'indexAnnouncements']);
    Route::get('/dosen/announcements', [CmsController::class, 'getActiveAnnouncements']);
    Route::post('/cms/announcements', [CmsController::class, 'storeAnnouncement']);
    Route::put('/cms/announcements/{id}', [CmsController::class, 'updateAnnouncement']);
    Route::delete('/cms/announcements/{id}', [CmsController::class, 'destroyAnnouncement']);

    // CMS FAQs
    Route::get('/cms/faqs', [CmsController::class, 'indexFaqs']);
    Route::post('/cms/faqs', [CmsController::class, 'storeFaq']);
    Route::put('/cms/faqs/{id}', [CmsController::class, 'updateFaq']);
    Route::delete('/cms/faqs/{id}', [CmsController::class, 'destroyFaq']);

    // CMS Templates
    Route::get('/cms/templates', [CmsController::class, 'indexTemplates']);
    Route::post('/cms/templates/upload', [CmsController::class, 'uploadTemplate']);

    // CMS User Roles & Access
    Route::get('/admin/cms/users', [CmsController::class, 'getUsers']);
    Route::post('/admin/cms/users/{id}/assign-role', [CmsController::class, 'assignRole']);

    // Support Tickets (Pesan ke Admin & Pengelolaan CMS Admin)
    Route::post('/support-tickets', [SupportTicketController::class, 'store']);
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{id}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{id}/messages', [SupportTicketController::class, 'addMessage']);

    Route::get('/admin/support-tickets', [SupportTicketController::class, 'adminIndex']);
    Route::get('/admin/support-tickets/{id}', [SupportTicketController::class, 'adminShow']);
    Route::post('/admin/support-tickets/{id}/reply', [SupportTicketController::class, 'reply']);
    Route::patch('/admin/support-tickets/{id}/status', [SupportTicketController::class, 'updateStatus']);
});

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

// Auth Routes with strict rate limit
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/logout', [UserController::class, 'logout']);
});

// General API Routes with global rate limit
Route::middleware(['throttle:api'])->group(function () {
    Route::get('/users/{id}', [UserController::class, 'profile']);
    Route::post('/users/{id}/scholar', [ScholarController::class, 'updateScholarId']);
    Route::post('/users/{id}/sync', [ScholarController::class, 'sync']);
    Route::get('/scholar/check/{scholar_id}', [ScholarController::class, 'checkId']);
    
    Route::post('/users/{id}/scopus', [ScopusController::class, 'updateScopusId']);
    Route::post('/users/{id}/sync-scopus', [ScopusController::class, 'sync']);
    Route::get('/scopus/check/{scopus_id}', [ScopusController::class, 'checkId']);
    
    Route::post('/documents', [DocumentController::class, 'upload']);
    Route::get('/users/{id}/documents', [DocumentController::class, 'getUserDocuments']);
    
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
    
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/admin/activity-logs', [ActivityLogController::class, 'store']);
    
    Route::get('/leaderboard', [UserController::class, 'leaderboard']);
    Route::get('/charts/prodi', [UserController::class, 'chartProdi']);
    Route::get('/charts/fakultas', [UserController::class, 'chartFakultas']);
    Route::get('/weights', [DocumentController::class, 'getWeights']);
    Route::get('/accreditation-periods', [DocumentController::class, 'getAccreditationPeriodsApi']);
    Route::get('/dashboard/stats', [UserController::class, 'getStats']);
});

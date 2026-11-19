<?php

use App\Http\Controllers\AdminBranchController;
use App\Http\Controllers\AdminCompanyController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CompanyEnterController;
use App\Http\Controllers\DocumentPrintController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportFileController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ReportPrintController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Single login for both panels (rendered by the Filament login page).
Route::get('/login', fn () => redirect()->to(Filament::getPanel('admin')->getLoginUrl()))->name('login');

// "/" → the user's panel (/admin or /app), or the login page.
Route::get('/', HomeController::class)->name('home');

// Language switch (guests: session; logged in: saved on the account).
Route::post('/locale/{locale}', LocaleController::class)->name('locale.switch');

// Admin top-bar branch: everything branch-based follows it.
Route::post('/admin-branch', AdminBranchController::class)->middleware('auth')->name('admin.branch.switch');

// The button next to the company name: KurdishMedicus ↔ Al-Fadhaa' Bureau (separate data; Al-Fadhaa'
// Bureau has no login of its own — the same person is signed in there with a one-minute link).
Route::post('/company', AdminCompanyController::class)->middleware('auth')->name('admin.company.switch');
Route::get('/company/enter', CompanyEnterController::class)->middleware('signed')->name('company.enter');

// PWA
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::view('/offline', 'pwa.offline')->name('pwa.offline');

// Labels and import files (both panels link here).
Route::middleware(['auth', 'track.session'])->group(function () {
    Route::get('/labels/print', LabelPrintController::class)->middleware('signed')->name('labels.print');
    Route::get('/imports/{import}/original', [ImportFileController::class, 'original'])->name('imports.original');
    Route::get('/imports/{import}/errors', [ImportFileController::class, 'errors'])->name('imports.errors');
    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.show');

    // Print pages and PDFs of reports and documents (Phase 5).
    Route::get('/print/reports/{report}', ReportPrintController::class)->name('reports.print');
    Route::get('/print/{type}/{id}', DocumentPrintController::class)->whereNumber('id')->name('documents.print');
});

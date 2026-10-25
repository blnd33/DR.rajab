<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PwaController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// Single login for both panels (rendered by the Filament login page).
Route::get('/login', fn () => redirect()->to(Filament::getPanel('admin')->getLoginUrl()))->name('login');

// "/" → the user's panel (/admin or /app), or the login page.
Route::get('/', HomeController::class)->name('home');

// Language switch (guests: session; logged in: saved on the account).
Route::post('/locale/{locale}', LocaleController::class)->name('locale.switch');

// PWA
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::view('/offline', 'pwa.offline')->name('pwa.offline');

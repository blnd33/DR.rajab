<?php

use App\Http\Controllers\Ops\BranchSwitchController;
use App\Http\Controllers\Ops\PushSubscriptionController;
use App\Livewire\Ops\Home;
use App\Livewire\Ops\Notifications;
use App\Livewire\Ops\Profile;
use App\Livewire\Ops\Scan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operations app (/app) — doctors, cashiers, branch staff
|--------------------------------------------------------------------------
| Middleware (bootstrap/app.php): web, auth, track.session, ops
*/

Route::livewire('/', Home::class)->name('home');
Route::livewire('/scan', Scan::class)->name('scan');
Route::livewire('/notifications', Notifications::class)->name('notifications');
Route::livewire('/profile', Profile::class)->name('profile');

Route::post('/branch', BranchSwitchController::class)->name('branch.switch');

Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

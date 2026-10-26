<?php

use App\Http\Controllers\Ops\BranchSwitchController;
use App\Http\Controllers\Ops\CountScanSyncController;
use App\Http\Controllers\Ops\PatientLookupController;
use App\Http\Controllers\Ops\PushSubscriptionController;
use App\Http\Controllers\Ops\SurgerySyncController;
use App\Livewire\Ops\Cash\CashHub;
use App\Livewire\Ops\Cash\CloseDay;
use App\Livewire\Ops\Cash\ExchangeCreate;
use App\Livewire\Ops\Cash\ExpenseCreate;
use App\Livewire\Ops\Cash\SaleCreate;
use App\Livewire\Ops\Clinical\KitIndex;
use App\Livewire\Ops\Clinical\KitShow;
use App\Livewire\Ops\Clinical\SurgeryCreate;
use App\Livewire\Ops\Clinical\SurgeryIndex;
use App\Livewire\Ops\Clinical\SurgeryShow;
use App\Livewire\Ops\Home;
use App\Livewire\Ops\Movement\CountShow;
use App\Livewire\Ops\Movement\CountStart;
use App\Livewire\Ops\Movement\RequestCreate;
use App\Livewire\Ops\Movement\RequestShow;
use App\Livewire\Ops\Movement\ReturnCreate;
use App\Livewire\Ops\Movement\Tasks;
use App\Livewire\Ops\Movement\TransferCreate;
use App\Livewire\Ops\Movement\TransferShow;
use App\Livewire\Ops\Movement\WriteOffCreate;
use App\Livewire\Ops\Notifications;
use App\Livewire\Ops\Profile;
use App\Livewire\Ops\Recall;
use App\Livewire\Ops\Scan;
use App\Livewire\Ops\Stock\Index as StockIndex;
use App\Livewire\Ops\Stock\ProductShow;
use App\Livewire\Ops\Stock\Receive;
use App\Livewire\Ops\Stock\UnitShow;
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

// Stock (Phase 1)
Route::livewire('/stock', StockIndex::class)->name('stock.index');
Route::livewire('/stock/receive', Receive::class)->name('stock.receive');
Route::livewire('/stock/products/{product}', ProductShow::class)->name('stock.product');
Route::livewire('/stock/units/{unit}', UnitShow::class)->name('stock.unit');
Route::livewire('/recall', Recall::class)->name('recall');

// Movement (Phase 2)
Route::livewire('/tasks', Tasks::class)->name('tasks');
Route::livewire('/transfers/new', TransferCreate::class)->name('transfers.create');
Route::livewire('/transfers/{transfer}', TransferShow::class)->name('transfers.show');
Route::livewire('/requests/new', RequestCreate::class)->name('requests.create');
Route::livewire('/requests/{stockRequest}', RequestShow::class)->name('requests.show');
Route::livewire('/counts/new', CountStart::class)->name('counts.create');
Route::livewire('/counts/{count}', CountShow::class)->name('counts.show');
Route::livewire('/write-offs/new', WriteOffCreate::class)->name('write-offs.create');
Route::livewire('/returns/new', ReturnCreate::class)->name('returns.create');

// Clinical (Phase 3)
Route::livewire('/surgeries', SurgeryIndex::class)->name('surgeries.index');
Route::livewire('/surgeries/new', SurgeryCreate::class)->name('surgeries.create');
Route::livewire('/surgeries/{surgery}', SurgeryShow::class)->name('surgeries.show');
Route::livewire('/kits', KitIndex::class)->name('kits.index');
Route::livewire('/kits/{kit}', KitShow::class)->name('kits.show');

// Phase 4 — cashier: boxes, sales at the counter, expenses, exchange, daily closing.
Route::livewire('/cash', CashHub::class)->name('cash');
Route::livewire('/cash/sale', SaleCreate::class)->name('cash.sale');
Route::livewire('/cash/expense', ExpenseCreate::class)->name('cash.expense');
Route::livewire('/cash/exchange', ExchangeCreate::class)->name('cash.exchange');
Route::livewire('/cash/{box}/close', CloseDay::class)->name('cash.close');
Route::get('/lookup/patients', PatientLookupController::class)->name('lookup.patients');

// Offline-tolerant sync: stock count scans queued on the phone (idempotent by client_uuid).
Route::post('/sync/count-scans', CountScanSyncController::class)->middleware('throttle:sync')->name('sync.count-scans');
Route::post('/sync/surgeries', SurgerySyncController::class)->middleware('throttle:sync')->name('sync.surgeries');

Route::post('/branch', BranchSwitchController::class)->name('branch.switch');

Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push.store');
Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

<?php

use App\Enums\Role;
use App\Http\Controllers\DocumentPrintController;
use App\Models\CashBoxClosing;
use App\Models\KitCheckout;
use App\Models\PurchaseReceipt;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Models\Transfer;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMoneySeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Printed documents (P5-3): every document prints in every language, right-to-left where it
 * should be, with codes kept LTR; nobody prints what they may not open.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, ExpenseCategorySeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'superadmin']);
    $this->actingAs($this->admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class, DemoMoneySeeder::class]);
});

function documents(): array
{
    return [
        'sales-invoice' => SalesInvoice::query()->firstOrFail(),
        'goods-received' => PurchaseReceipt::query()->firstOrFail(),
        'transfer' => Transfer::query()->firstOrFail(),
        'surgery' => Surgery::query()->where('status', 'completed')->firstOrFail(),
        'kit-checkout' => KitCheckout::query()->firstOrFail(),
        'supplier-statement' => Supplier::query()->where('code', 'NVJ')->firstOrFail(),
        'cash-closing' => CashBoxClosing::query()->firstOrFail(),
    ];
}

it('prints every document in every language', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    foreach (documents() as $type => $record) {
        $response = $this->get(DocumentPrintController::url($type, $record));
        expect($response->status())->toBe(200, "{$type} in {$locale}");
        $response->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false);
    }

    $this->get(DocumentPrintController::url('surgery', documents()['surgery']))->assertSee(documents()['surgery']->number);
})->with(['en', 'ar', 'ckb']);

it('refuses documents of branches and people the user may not see', function () {
    $bgw = branch('BGW')->id;
    $elsewhere = Transfer::query()->where('from_branch_id', '<>', $bgw)->where('to_branch_id', '<>', $bgw)->firstOrFail();
    $this->actingAs(userWithRole(Role::BranchStaff, [branch('BGW')]));

    expect($this->get(DocumentPrintController::url('transfer', $elsewhere))->status())->toBe(403);

    foreach (['sales-invoice', 'surgery', 'cash-closing', 'supplier-statement'] as $type) {
        expect($this->get(DocumentPrintController::url($type, documents()[$type]))->status())->toBe(403, $type);
    }

    expect($this->get('/print/unknown/1')->status())->toBe(404);
});

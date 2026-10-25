<?php

use App\Enums\BarcodeSymbology;
use App\Enums\Role;
use App\Exceptions\Domain\BranchClosureBlocked;
use App\Models\LowStock;
use App\Models\Product;
use App\Models\UnrecognizedScan;
use App\Notifications\LowStockDigest;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Branches\BranchService;
use App\Services\Catalog\ProductService;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\LowStockDetector;
use App\Services\Stock\RecallSearch;
use App\Services\Stock\StockMovementService;
use App\Services\Stock\StockQuery;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->implant = Product::factory()->gtin('09501101530003')->create(['sku' => 'KN-1']);
    $this->screw = Product::factory()->lot()->create(['sku' => 'SC-1']);
    $this->resolver = app(ScanResolver::class);

    app(StockMovementService::class)->receive($this->dhk, [
        ReceiveLine::fromArray(['product_id' => $this->implant->id, 'serial' => 'SN-1', 'lot' => 'RC-77', 'expiry_date' => '2030-01-31', 'cost_currency' => 'USD', 'unit_cost' => '100']),
        ReceiveLine::fromArray(['product_id' => $this->screw->id, 'lot' => 'RC-77', 'quantity' => 30, 'expiry_date' => '2030-06-30', 'cost_currency' => 'EUR', 'unit_cost' => '2']),
    ], $this->admin);
});

it('resolves a GS1 scan to the existing unit', function () {
    $result = $this->resolver->resolve("]d201095011015300031730013110RC-77\x1D21SN-1");

    expect($result->kind)->toBe(ScanResultKind::Unit)->and($result->unit->serial)->toBe('SN-1');
});

it('resolves a GS1 scan of a new serial to a prefilled receive line', function () {
    $result = $this->resolver->resolve('(01)09501101530003(17)300131(10)RC-78(21)SN-NEW');

    expect($result->kind)->toBe(ScanResultKind::NewUnit)
        ->and($result->prefill())->toMatchArray(['product_id' => $this->implant->id, 'serial' => 'SN-NEW', 'lot' => 'RC-78', 'expiry_date' => '2030-01-31']);
});

it('matches EAN-13 / GTIN-14 forms, SKUs, extra barcodes and lot numbers', function () {
    app(ProductService::class)->addBarcode($this->screw, 'SUP-123', BarcodeSymbology::Supplier);

    expect($this->resolver->resolve('9501101530003')->product?->id)->toBe($this->implant->id)
        ->and($this->resolver->resolve('kn-1')->product?->id)->toBe($this->implant->id)
        ->and($this->resolver->resolve('SUP-123')->kind)->toBe(ScanResultKind::Lot)
        ->and($this->resolver->resolve('SN-1')->kind)->toBe(ScanResultKind::Unit)
        ->and($this->resolver->resolve('RC-77')->kind)->toBe(ScanResultKind::LotSearch);
});

it('saves unknown codes with their bytes for new formats', function () {
    $result = $this->resolver->resolve("ABC\x1DXYZ-UNKNOWN", user: $this->admin, branchId: $this->dhk->id);

    expect($result->found())->toBeFalse()
        ->and(UnrecognizedScan::query()->sole()->raw_hex)->toBe(bin2hex("ABC\x1DXYZ-UNKNOWN"));
});

it('finds every item of a lot for a recall', function () {
    $results = app(RecallSearch::class)->search('RC-77');

    expect($results['exact'])->toBeTrue()
        ->and($results['units']->pluck('serial')->all())->toBe(['SN-1'])
        ->and($results['batches']->sum('quantity'))->toEqual(30)
        ->and(app(RecallSearch::class)->search('C-7')['exact'])->toBeFalse();
});

it('values stock per currency and limits it to the user\'s branches', function () {
    expect(app(StockQuery::class)->valueByCurrency(null))->toBe(['EUR' => '60.0000', 'USD' => '100.0000'])
        ->and(app(StockQuery::class)->valueByCurrency([branch('EBL')->id]))->toBe([]);

    $ebl = userWithRole(Role::BranchStaff, [branch('EBL')]);

    expect(app(StockQuery::class)->units($ebl)->count())->toBe(0)
        ->and(app(StockQuery::class)->units(userWithRole(Role::BranchStaff, [$this->dhk]))->count())->toBe(1);
});

it('lists low stock against branch minimums and sends one digest per branch', function () {
    Notification::fake();
    $staff = userWithRole(Role::BranchStaff, [$this->dhk]);

    branch('EBL');
    $this->implant->update(['min_stock_default' => 3]);
    app(ProductService::class)->setBranchMinimum($this->screw, $this->dhk->id, 50);

    $low = LowStock::query()->where('branch_id', $this->dhk->id)->get()->keyBy('product_id');

    expect($low[$this->implant->id]->shortage)->toBe(2)
        ->and($low[$this->screw->id]->min_stock)->toBe(50)
        // EBL is open and has none of the implant at all.
        ->and(LowStock::query()->where('product_id', $this->implant->id)->count())->toBe(2);

    app(LowStockDetector::class)->notify();

    Notification::assertSentTo($staff, LowStockDigest::class, fn ($n) => $n->branchId === $this->dhk->id && $n->productCount === 2);
});

it('blocks closing a branch that still has stock', function () {
    expect(fn () => app(BranchService::class)->close($this->dhk, 'Moving', $this->admin))
        ->toThrow(BranchClosureBlocked::class)
        ->and(app(BranchService::class)->canHardDelete($this->dhk))->toBeFalse();
});

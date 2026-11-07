<?php

use App\Enums\Role;
use App\Exceptions\Domain\StockRuleViolation;
use App\Livewire\Ops\Stock\Receive;
use App\Models\Product;
use App\Models\StockBatch;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Livewire\Livewire;

/*
 * SN or LOT, whichever the box shows (doctor's answer 2026-11-07): a lot item may carry the
 * box's own serial. That box is then its own record of 1 and its SN finds it again.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->stock = app(StockMovementService::class);
    $this->dhk = branch('DHK');
    $this->staff = userWithRole(Role::BranchStaff, [$this->dhk]);
    $this->head = Product::factory()->lot()->create(['sku' => '5880.10.280', 'gtin' => '08033390051835']);
});

function boxLine(Product $product, array $overrides = []): ReceiveLine
{
    return ReceiveLine::fromArray($overrides + [
        'product_id' => $product->id,
        'quantity' => 1,
        'expiry_date' => now()->addYear()->toDateString(),
        'unit_cost' => '100',
        'cost_currency' => 'USD',
    ]);
}

it('keeps a box with its SN apart from boxes with only a LOT', function () {
    $this->stock->receive($this->dhk, [
        boxLine($this->head, ['lot' => 'L1', 'serial' => 'SN-A']),
        boxLine($this->head, ['lot' => 'L1', 'serial' => 'SN-B']),
        boxLine($this->head, ['lot' => 'L1', 'quantity' => 3]),
        boxLine($this->head, ['serial' => 'SN-C']),
    ], $this->staff);

    expect(StockBatch::query()->whereNotNull('serial')->pluck('quantity', 'serial')->all())->toBe(['SN-A' => 1, 'SN-B' => 1, 'SN-C' => 1])
        ->and(StockBatch::query()->whereNull('serial')->sole()->quantity)->toBe(3)
        ->and(StockBatch::query()->where('serial', 'SN-A')->sole()->codeLabel())->toBe('SN SN-A · LOT L1');
});

it('refuses an SN box with more than one piece, or an SN that is already in stock', function () {
    expect(fn () => $this->stock->receive($this->dhk, [boxLine($this->head, ['serial' => 'SN-A', 'quantity' => 2])], $this->staff))
        ->toThrow(StockRuleViolation::class, 'always has quantity 1');

    expect(fn () => $this->stock->receive($this->dhk, [boxLine($this->head, ['serial' => 'SN-A']), boxLine($this->head, ['serial' => 'SN-A'])], $this->staff))
        ->toThrow(StockRuleViolation::class, 'in the list twice');

    $this->stock->receive($this->dhk, [boxLine($this->head, ['serial' => 'SN-A'])], $this->staff);

    expect(fn () => $this->stock->receive($this->dhk, [boxLine($this->head, ['serial' => 'SN-A'])], $this->staff))
        ->toThrow(StockRuleViolation::class, 'already in the system');

    $implant = Product::factory()->create(['sku' => 'SER-1']);

    expect(fn () => $this->stock->receive($this->dhk, [boxLine($implant, ['serial' => 'SN-A'])], $this->staff))
        ->toThrow(StockRuleViolation::class, 'already in the system');
});

it('finds the exact box by its SN, from the GS1 barcode or typed', function () {
    $this->stock->receive($this->dhk, [
        boxLine($this->head, ['lot' => 'L1', 'serial' => 'SN-A']),
        boxLine($this->head, ['lot' => 'L1', 'serial' => 'SN-B']),
    ], $this->staff);

    $resolver = app(ScanResolver::class);
    $gs1 = $resolver->resolve(']C101080333900518351728013110L1'."\x1D".'21SN-B');
    $typed = $resolver->resolve('SN-A');

    expect($gs1->kind)->toBe(ScanResultKind::Lot)
        ->and($gs1->batches->pluck('serial')->all())->toBe(['SN-B'])
        ->and($typed->kind)->toBe(ScanResultKind::Lot)
        ->and($typed->product?->id)->toBe($this->head->id)
        ->and($typed->batches->pluck('serial')->all())->toBe(['SN-A']);
});

it('keeps the SN when the box is corrected', function () {
    $this->stock->receive($this->dhk, [boxLine($this->head, ['lot' => 'L1', 'serial' => 'SN-A'])], $this->staff);
    $admin = userWithRole(Role::SuperAdmin, [$this->dhk]);

    $this->stock->correctBatch(StockBatch::query()->sole(), ['lot' => 'L2'], 'Lot misread', $admin);

    expect(StockBatch::query()->where('quantity', 1)->sole())->serial->toBe('SN-A')->lot->toBe('L2');
});

it('on the phone: a box with an SN gets its own line, boxes with only a LOT add up', function () {
    $screen = Livewire::actingAs($this->staff)->test(Receive::class)
        ->call('handleScan', ']C101080333900518351728013110L1'."\x1D".'21SN-A', 'camera')
        ->call('handleScan', ']C101080333900518351728013110L1'."\x1D".'21SN-B', 'camera')
        ->call('handleScan', ']C101080333900518351728013110L1'."\x1D".'21SN-B', 'camera')
        ->call('handleScan', ']C101080333900518351728013110L1', 'camera')
        ->call('handleScan', ']C101080333900518351728013110L1', 'camera')
        ->assertSee('(SN)')
        ->assertSee('(LOT)');

    $lines = collect($screen->get('lines'));

    expect($lines)->toHaveCount(3)
        ->and($lines->whereNotNull('serial')->pluck('serial')->sort()->values()->all())->toBe(['SN-A', 'SN-B'])
        ->and($lines->whereNull('serial')->sole()['quantity'])->toBe(2);

    $screen->call('save');

    expect(StockBatch::query()->sum('quantity'))->toEqual(4)
        ->and(StockBatch::query()->whereNotNull('serial')->count())->toBe(2);
});

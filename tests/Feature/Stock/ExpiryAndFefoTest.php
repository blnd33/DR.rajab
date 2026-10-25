<?php

use App\Enums\Role;
use App\Models\ExpiryLevel;
use App\Models\Product;
use App\Notifications\ExpiryLevelEntered;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Expiry\ExpiryLevelService;
use App\Services\Expiry\ExpiryTransitionNotifier;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\FefoPicker;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->levels = app(ExpiryLevelService::class);
});

function levelsWith(array $changes): array
{
    $levels = ExpiryLevelService::defaults();

    foreach ($changes as $index => $values) {
        $levels[$index] = array_merge($levels[$index], $values);
    }

    return $levels;
}

it('seeds the five default levels from the brief', function () {
    $classifier = app(ExpiryClassifier::class);

    expect(ExpiryLevel::query()->count())->toBe(5)
        ->and($classifier->levelFor(-3)->name)->toBe('Expired')
        ->and($classifier->levelFor(0)->name)->toBe('Expired')
        ->and($classifier->levelFor(1)->name)->toBe('Critical')
        ->and($classifier->levelFor(90)->name)->toBe('Critical')
        ->and($classifier->levelFor(91)->name)->toBe('Warning')
        ->and($classifier->levelFor(365)->name)->toBe('Attention')
        ->and($classifier->levelFor(5000)->name)->toBe('Good');
});

it('refuses gaps, overlaps and a closed last level', function (array $changes) {
    expect(fn () => $this->levels->saveAll(levelsWith($changes)))->toThrow(ValidationException::class);
})->with([
    'gap' => [[2 => ['min_days' => 95]]],
    'overlap' => [[2 => ['min_days' => 80]]],
    'last level closed' => [[4 => ['max_days' => 999]]],
    'first level not at day 1' => [[1 => ['min_days' => 5]]],
    'missing English name' => [[3 => ['name' => ['ar' => 'انتباه']]]],
    'bad color' => [[3 => ['color' => 'yellow']]],
]);

it('always keeps the Expired level with its fixed rule', function () {
    $levels = ExpiryLevelService::defaults();
    array_shift($levels);

    expect(fn () => $this->levels->saveAll($levels))->toThrow(ValidationException::class);

    // Trying to move the expired rule is ignored: it stays ≤ 0 and blocks usage.
    $this->levels->saveAll(levelsWith([0 => ['max_days' => 30, 'blocks_usage' => false, 'color' => '#000000']]));
    $expired = ExpiryLevel::query()->where('is_expired_level', true)->sole();

    expect($expired->max_days)->toBe(0)->and($expired->blocks_usage)->toBeTrue()->and($expired->color)->toBe('#000000');
});

it('lets the Super Admin add and remove levels and picks readable text colors', function () {
    $levels = ExpiryLevelService::defaults();
    array_splice($levels, 2, 0, [['name' => ['en' => 'Soon'], 'min_days' => 91, 'max_days' => 120, 'color' => '#FDE047']]);
    $levels[3]['min_days'] = 121;

    $this->levels->saveAll($levels);

    $soon = ExpiryLevel::query()->where('min_days', 91)->sole();

    expect(ExpiryLevel::query()->count())->toBe(6)
        ->and($soon->text_color)->toBe(ExpiryLevelService::DARK_TEXT)
        ->and(ExpiryLevelService::textColorFor('#111827'))->toBe(ExpiryLevelService::LIGHT_TEXT);
});

it('suggests the soonest-expiring valid item first (FEFO)', function () {
    $dhk = branch('DHK');
    $user = userWithRole(Role::SuperAdmin, [$dhk]);
    $product = Product::factory()->create();

    app(StockMovementService::class)->receive($dhk, [
        ReceiveLine::fromArray(['product_id' => $product->id, 'serial' => 'LATE', 'expiry_date' => now()->addDays(400)->toDateString(), 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $product->id, 'serial' => 'EXPIRED', 'expiry_date' => now()->subDays(2)->toDateString(), 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $product->id, 'serial' => 'SOON', 'expiry_date' => now()->addDays(20)->toDateString(), 'cost_currency' => 'USD']),
    ], $user);

    $fefo = app(FefoPicker::class);

    expect($fefo->suggest($product, $dhk->id)->serial)->toBe('SOON')
        ->and($fefo->units($product, $dhk->id)->pluck('serial')->all())->toBe(['SOON', 'LATE']);

    // A level marked "blocks usage" is skipped too.
    $this->levels->saveAll(levelsWith([1 => ['blocks_usage' => true]]));

    expect(app(FefoPicker::class)->suggest($product, $dhk->id)->serial)->toBe('LATE');
});

it('allocates lot quantities over buckets in FEFO order', function () {
    $dhk = branch('DHK');
    $user = userWithRole(Role::SuperAdmin, [$dhk]);
    $product = Product::factory()->lot()->create();

    app(StockMovementService::class)->receive($dhk, [
        ReceiveLine::fromArray(['product_id' => $product->id, 'lot' => 'B', 'quantity' => 10, 'expiry_date' => now()->addDays(300)->toDateString(), 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $product->id, 'lot' => 'A', 'quantity' => 4, 'expiry_date' => now()->addDays(30)->toDateString(), 'cost_currency' => 'USD']),
    ], $user);

    $allocation = app(FefoPicker::class)->allocate($product, $dhk->id, 6);

    expect(array_map(fn ($a) => [$a['batch']->lot, $a['quantity']], $allocation))->toBe([['A', 4], ['B', 2]]);
});

it('notifies once per branch when items enter a new level', function () {
    Notification::fake();

    $dhk = branch('DHK');
    $staff = userWithRole(Role::BranchStaff, [$dhk]);
    $product = Product::factory()->create();

    app(StockMovementService::class)->receive($dhk, [
        ReceiveLine::fromArray(['product_id' => $product->id, 'serial' => 'A', 'expiry_date' => now()->addDays(100)->toDateString(), 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $product->id, 'serial' => 'B', 'expiry_date' => now()->addDays(100)->toDateString(), 'cost_currency' => 'USD']),
    ], userWithRole(Role::SuperAdmin, [$dhk]));

    // Nothing changed level since receiving.
    expect(app(ExpiryTransitionNotifier::class)->run())->toBe([]);

    // 20 days later both units move from Warning (91–180) into Critical (1–90).
    $this->travel(20)->days();
    app(ExpiryClassifier::class)->flush();

    $entered = app(ExpiryTransitionNotifier::class)->run();
    $critical = ExpiryLevel::query()->where('min_days', 1)->sole();

    expect($entered[$critical->id][$dhk->id])->toBe(2);
    Notification::assertSentTo($staff, ExpiryLevelEntered::class, fn ($n) => $n->quantity === 2 && $n->levelId === $critical->id);
    // One digest for the branch, not one per unit.
    expect(Notification::sent($staff, ExpiryLevelEntered::class))->toHaveCount(1);
});

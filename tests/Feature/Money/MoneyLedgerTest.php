<?php

use App\Enums\ClosingStatus;
use App\Enums\IssueType;
use App\Enums\MoneyTransactionType;
use App\Enums\Role;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\FxSnapshot;
use App\Models\Issue;
use App\Models\MoneyTransaction;
use App\Services\Money\BoxTransferService;
use App\Services\Money\CashBoxService;
use App\Services\Money\CashClosingService;
use App\Services\Money\CurrencyExchangeService;
use App\Services\Money\FxService;
use App\Services\Money\MoneyLedgerService;
use App\Support\Money\FxConverter;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;

/*
 * Rates & snapshots, the money ledger, daily closing, box transfers and currency exchange
 * (Modules 19–20, brief §3.3).
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->bgw = branch('BGW');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->cashier = userWithRole(Role::Cashier, [$this->dhk]);
    $this->fx = app(FxService::class);
    $this->boxes = app(CashBoxService::class);
});

function rates($test, string $usdIqd = '1460', string $eurUsd = '1.08'): void
{
    $test->fx->setRate('USD', 'IQD', $usdIqd, $test->admin);
    $test->fx->setRate('EUR', 'USD', $eurUsd, $test->admin);
}

it('needs both rates before a snapshot exists, then converts with one snapshot', function () {
    $this->fx->setRate('USD', 'IQD', '1460', $this->admin);
    expect(FxSnapshot::query()->count())->toBe(0)
        ->and(fn () => $this->fx->currentOrFail())->toThrow(MoneyRuleViolation::class);

    $this->fx->setRate('EUR', 'USD', '1.08', $this->admin);
    $snapshot = $this->fx->currentOrFail();
    $convert = FxConverter::with($snapshot);

    expect($convert->convert('1000', 'USD', 'IQD'))->toBe('1460000.0000')
        ->and($convert->convert('1460000', 'IQD', 'USD'))->toBe('1000.0000')
        ->and($convert->convert('100', 'EUR', 'USD'))->toBe('108.0000')
        ->and($convert->convert('100', 'EUR', 'IQD'))->toBe('157680.0000')
        ->and($convert->rate('EUR', 'IQD'))->toBe('1576.80000000');

    // A new rate makes a new snapshot; the old one never changes.
    $this->fx->setRate('USD', 'IQD', '1500', $this->admin);
    expect(FxSnapshot::query()->count())->toBe(2)
        ->and((string) $snapshot->fresh()->usd_iqd)->toBe('1460.00000000');
});

it('lets only permitted users set rates; the ECB value is only a suggestion', function () {
    expect(fn () => $this->fx->setRate('USD', 'IQD', '1460', $this->cashier))->toThrow(AuthorizationException::class);

    rates($this);
    Http::fake(['*ecb.europa.eu*' => Http::response("<Cube currency='USD' rate='1.0912'/>")]);
    $suggestion = $this->fx->fetchEcb();

    expect($suggestion->status->value)->toBe('suggested')
        ->and(FxSnapshot::query()->count())->toBe(1);

    $this->fx->confirm($suggestion, $this->admin);
    expect((string) $this->fx->currentOrFail()->eur_usd)->toBe('1.09120000');
});

it('posts signed amounts with a running balance and never lets cash go below zero', function () {
    rates($this);
    $box = $this->boxes->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'IQD', 'opening_balance' => '500000'], $this->admin);
    $ledger = app(MoneyLedgerService::class);

    $out = $ledger->post($box, MoneyTransactionType::Expense, '-120000', $this->admin);

    expect((string) $box->fresh()->current_balance)->toBe('380000.0000')
        ->and((string) $out->balance_after)->toBe('380000.0000')
        ->and((string) $out->amount_usd)->toBe('-82.1918')
        ->and(fn () => $ledger->post($box, MoneyTransactionType::Expense, '-400000', $this->admin))->toThrow(MoneyRuleViolation::class);

    $ledger->reverse($out, $this->admin, 'Typed twice');
    expect((string) $box->fresh()->current_balance)->toBe('500000.0000')
        ->and(fn () => $ledger->reverse($out, $this->admin, 'again'))->toThrow(MoneyRuleViolation::class)
        ->and(fn () => $out->fresh()->update(['amount' => '1']))->toThrow(LogicException::class);
});

it('closes the day: a difference needs a reason, is booked and becomes an issue', function () {
    rates($this);
    $box = $this->boxes->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'USD', 'opening_balance' => '1000'], $this->admin);
    $closings = app(CashClosingService::class);

    $closing = $closings->start($box, $this->cashier);
    expect((string) $closing->expected_balance)->toBe('1000.0000')
        ->and(fn () => $closings->submit($closing, '990', $this->cashier))->toThrow(MoneyRuleViolation::class);

    $closing = $closings->submit($closing, '990', $this->cashier, 'Change given wrongly');

    expect($closing->status)->toBe(ClosingStatus::Submitted)
        ->and((string) $closing->difference)->toBe('-10.0000')
        ->and((string) $box->fresh()->current_balance)->toBe('990.0000')
        ->and(MoneyTransaction::query()->where('type', MoneyTransactionType::ClosingAdjustment)->count())->toBe(1)
        ->and(Issue::query()->where('type', IssueType::CashDifference)->count())->toBe(1)
        ->and(fn () => $closings->submit($closing, '990', $this->cashier, 'x'))->toThrow(MoneyRuleViolation::class);

    expect(fn () => $closings->review($closing, $this->cashier))->toThrow(AuthorizationException::class);
    $closings->review($closing, $this->admin, 'Checked with the cashier');
    expect($closing->fresh()->status)->toBe(ClosingStatus::Reviewed);
});

it('moves cash between branches and cancels a transfer on its way', function () {
    rates($this);
    $from = $this->boxes->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'USD', 'opening_balance' => '2000'], $this->admin);
    $to = $this->boxes->create($this->bgw, ['name' => ['en' => 'Cash'], 'currency' => 'USD'], $this->admin);
    $iqd = $this->boxes->create($this->bgw, ['name' => ['en' => 'Cash'], 'currency' => 'IQD'], $this->admin);
    $transfers = app(BoxTransferService::class);

    expect(fn () => $transfers->send($from, $iqd, '100', $this->admin))->toThrow(MoneyRuleViolation::class);

    $transfer = $transfers->send($from, $to, '500', $this->admin);
    expect((string) $from->fresh()->current_balance)->toBe('1500.0000')->and((string) $to->fresh()->current_balance)->toBe('0.0000');

    $transfers->receive($transfer, $this->admin);
    expect((string) $to->fresh()->current_balance)->toBe('500.0000');

    $second = $transfers->send($from, $to, '200', $this->admin);
    $transfers->cancel($second, 'Wrong amount', $this->admin);
    expect((string) $from->fresh()->current_balance)->toBe('1500.0000');
});

it('exchanges money at a deal rate and keeps the gain or loss in USD', function () {
    rates($this, '1460');
    $usd = $this->boxes->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'USD', 'opening_balance' => '1000'], $this->admin);
    $iqd = $this->boxes->create($this->dhk, ['name' => ['en' => 'Cash'], 'currency' => 'IQD'], $this->admin);
    $exchanges = app(CurrencyExchangeService::class);

    // Sold 1,000 USD at 1,470 (better than the 1,460 reference): a small gain.
    $exchange = $exchanges->exchange($usd, $iqd, '1000', '1470', $this->admin);

    expect((string) $exchange->to_amount)->toBe('1470000.0000')
        ->and((string) $usd->fresh()->current_balance)->toBe('0.0000')
        ->and((string) $iqd->fresh()->current_balance)->toBe('1470000.0000')
        ->and((string) $exchange->gain_loss_usd)->toBe('6.8493');

    $exchanges->void($exchange, 'Customer changed their mind', $this->admin);
    expect((string) $usd->fresh()->current_balance)->toBe('1000.0000')->and((string) $iqd->fresh()->current_balance)->toBe('0.0000');
});

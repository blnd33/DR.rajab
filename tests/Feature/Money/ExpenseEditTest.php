<?php

use App\Enums\ApprovalStatus;
use App\Enums\ExpenseStatus;
use App\Enums\MoneyTransactionType;
use App\Enums\Role;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Approval;
use App\Models\ExpenseCategory;
use App\Models\MoneyTransaction;
use App\Services\Expenses\ExpenseService;
use App\Services\Money\CashBoxService;
use App\Services\Money\FxService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Expenses: the currency is chosen (and must match the box), and an expense can be edited:
 * a waiting one in place, a paid one corrected in its box (reverse + pay again).
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->accountant = userWithRole(Role::Accountant, [$this->dhk]);
    $this->cashier = userWithRole(Role::Cashier, [$this->dhk]);
    app(FxService::class)->setRate('USD', 'IQD', '1460', $this->admin);
    app(FxService::class)->setRate('EUR', 'USD', '1.10', $this->admin);

    $boxes = app(CashBoxService::class);
    $this->usdBox = $boxes->create($this->dhk, ['name' => ['en' => 'Cash USD'], 'currency' => 'USD', 'opening_balance' => '1000'], $this->admin);
    $this->eurBox = $boxes->create($this->dhk, ['name' => ['en' => 'Cash EUR'], 'currency' => 'EUR', 'opening_balance' => '500'], $this->admin);
    $this->category = ExpenseCategory::query()->create(['code' => 'FUEL', 'name' => ['en' => 'Fuel']]);
    $this->other = ExpenseCategory::query()->create(['code' => 'FOOD', 'name' => ['en' => 'Food']]);
});

function expenseData($test, array $overrides = []): array
{
    return $overrides + [
        'expense_category_id' => $test->category->id,
        'cash_box_id' => $test->eurBox->id,
        'currency' => 'EUR',
        'amount' => '40',
        'payee' => 'Petrol station',
    ];
}

it('records an expense in the chosen currency and refuses a box of another currency', function () {
    $expense = app(ExpenseService::class)->record($this->dhk, expenseData($this), $this->cashier);

    expect($expense->currency)->toBe('EUR')
        ->and($expense->status)->toBe(ExpenseStatus::Posted)
        ->and((string) $this->eurBox->fresh()->current_balance)->toBe('460.0000');

    expect(fn () => app(ExpenseService::class)->record($this->dhk, expenseData($this, ['cash_box_id' => $this->usdBox->id]), $this->cashier))
        ->toThrow(MoneyRuleViolation::class);
});

it('corrects a paid expense in its box: the old payment is reversed and the new amount paid', function () {
    $expense = app(ExpenseService::class)->record($this->dhk, expenseData($this), $this->cashier);
    $first = $expense->money_transaction_id;

    // The cashier may record but not edit.
    expect(fn () => app(ExpenseService::class)->update($expense, expenseData($this, ['amount' => '55']), $this->cashier, 'Typo'))
        ->toThrow(AuthorizationException::class);

    // A paid expense needs a reason.
    expect(fn () => app(ExpenseService::class)->update($expense, expenseData($this, ['amount' => '55']), $this->accountant))
        ->toThrow(MoneyRuleViolation::class);

    $edited = app(ExpenseService::class)->update($expense, expenseData($this, ['amount' => '55', 'expense_category_id' => $this->other->id]), $this->accountant, 'Typo in the amount');

    expect($edited->status)->toBe(ExpenseStatus::Posted)
        ->and((string) $edited->amount)->toBe('55.0000')
        ->and($edited->expense_category_id)->toBe($this->other->id)
        ->and($edited->money_transaction_id)->not->toBe($first)
        ->and(MoneyTransaction::query()->where('reverses_id', $first)->where('type', MoneyTransactionType::Reversal)->exists())->toBeTrue()
        ->and((string) $this->eurBox->fresh()->current_balance)->toBe('445.0000');

    // Moving it to a box of another currency (USD) works when the currency follows.
    $moved = app(ExpenseService::class)->update($edited, expenseData($this, ['cash_box_id' => $this->usdBox->id, 'currency' => 'USD', 'amount' => '60']), $this->accountant, 'Paid in dollars');

    expect($moved->currency)->toBe('USD')
        ->and((string) $this->eurBox->fresh()->current_balance)->toBe('500.0000')
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('940.0000');
});

it('edits a waiting expense in place and its approval request follows', function () {
    app(SettingsRepository::class)->set('approvals.expense_threshold_usd', 100);
    $expense = app(ExpenseService::class)->record($this->dhk, expenseData($this, ['cash_box_id' => $this->usdBox->id, 'currency' => 'USD', 'amount' => '300']), $this->cashier);

    expect($expense->status)->toBe(ExpenseStatus::PendingApproval);

    $edited = app(ExpenseService::class)->update($expense, expenseData($this, ['cash_box_id' => $this->usdBox->id, 'currency' => 'USD', 'amount' => '250']), $this->accountant);
    $approval = Approval::query()->where('approvable_id', $expense->id)->where('status', ApprovalStatus::Pending)->first();

    expect($edited->status)->toBe(ExpenseStatus::PendingApproval)
        ->and((string) $edited->amount)->toBe('250.0000')
        ->and((string) $approval->amount)->toBe('250.0000')
        ->and($edited->money_transaction_id)->toBeNull()
        ->and((string) $this->usdBox->fresh()->current_balance)->toBe('1000.0000');
});

it('does not edit a voided expense', function () {
    $expense = app(ExpenseService::class)->record($this->dhk, expenseData($this), $this->cashier);
    app(ExpenseService::class)->void($expense, 'Duplicate', $this->admin);

    expect(fn () => app(ExpenseService::class)->update($expense->fresh(), expenseData($this, ['amount' => '10']), $this->accountant, 'x'))
        ->toThrow(MoneyRuleViolation::class);
});

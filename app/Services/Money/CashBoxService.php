<?php

namespace App\Services\Money;

use App\Enums\CashBoxType;
use App\Enums\MoneyTransactionType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cash boxes and bank accounts (Module 19, P4-4): one cash box per branch and currency,
 * bank accounts as extra boxes; head-office boxes have no branch.
 */
class CashBoxService
{
    public function __construct(
        private readonly MoneyLedgerService $ledger,
        private readonly BranchScopeResolver $scope,
    ) {}

    /**
     * @param  array{name: array<string, string>|string, type?: string, currency: string, bank_name?: ?string, account_number?: ?string, iban?: ?string, allow_overdraft?: bool, opening_balance?: string|int|float|null}  $data
     */
    public function create(?Branch $branch, array $data, User $by): CashBox
    {
        $this->authorize($by, $branch?->id);
        $type = CashBoxType::from($data['type'] ?? CashBoxType::Cash->value);

        if ($type === CashBoxType::Cash && CashBox::query()->where('branch_id', $branch?->id)->where('currency', $data['currency'])->where('type', CashBoxType::Cash)->where('is_active', true)->exists()) {
            throw new MoneyRuleViolation(__('There is already an open :currency cash box here.', ['currency' => $data['currency']]), 'currency');
        }

        return DB::transaction(function () use ($branch, $data, $by, $type) {
            $box = CashBox::query()->create([
                'branch_id' => $branch?->id,
                'name' => $data['name'],
                'type' => $type,
                'currency' => $data['currency'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'allow_overdraft' => $type === CashBoxType::Bank && (bool) ($data['allow_overdraft'] ?? false),
                'current_balance' => '0',
                'is_active' => true,
                'opened_at' => now(),
            ]);

            $opening = BigDecimal::of((string) ($data['opening_balance'] ?? '0') ?: '0');

            if (! $opening->isZero()) {
                $this->ledger->post($box, MoneyTransactionType::OpeningBalance, (string) $opening, $by, $box, description: __('Opening balance'));
            }

            return $box->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, bank fields, allow_overdraft
     */
    public function update(CashBox $box, array $data, User $by): CashBox
    {
        $this->authorize($by, $box->branch_id);

        $box->fill(array_intersect_key($data, array_flip(['name', 'bank_name', 'account_number', 'iban'])));

        if ($box->type === CashBoxType::Bank && array_key_exists('allow_overdraft', $data)) {
            $box->allow_overdraft = (bool) $data['allow_overdraft'];
        }

        $box->save();

        return $box;
    }

    public function close(CashBox $box, User $by): CashBox
    {
        $this->authorize($by, $box->branch_id);

        if (! BigDecimal::of($box->current_balance)->isZero()) {
            throw new MoneyRuleViolation(__('Empty the box first (move or exchange its money).'));
        }

        $box->forceFill(['is_active' => false, 'closed_at' => now()])->save();

        return $box;
    }

    /**
     * Open boxes the user may use for a permission (their branches; head office with a global scope).
     *
     * @return array<int, string>
     */
    public function options(User $user, string $permission, ?string $currency = null, ?int $branchId = null): array
    {
        $ids = $this->scope->branchIdsFor($user, $permission);

        return CashBox::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids === [] ? [0] : $ids))
            ->orderByRaw('branch_id IS NULL')->orderBy('branch_id')->orderBy('currency')
            ->get()
            ->mapWithKeys(fn (CashBox $box) => [$box->id => $box->label()])
            ->all();
    }

    private function authorize(User $by, ?int $branchId): void
    {
        if (! $this->scope->allows($by, 'cash_boxes.manage', $branchId)) {
            throw new AuthorizationException(__('You are not allowed to manage cash boxes here.'));
        }
    }
}

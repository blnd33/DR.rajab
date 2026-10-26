<?php

namespace App\Services\Expenses;

use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Rent, salaries… (Module 21): every day the scheduler creates this month's expense of each
 * rule whose day has come — posted when the rule says so (and it is below the threshold),
 * otherwise waiting for approval.
 */
class RecurringExpenseService
{
    public function __construct(private readonly ExpenseService $expenses) {}

    /**
     * @return int expenses created
     */
    public function generateDue(?Carbon $today = null): int
    {
        $today ??= now(config('orthotrack.timezone'))->startOfDay();
        $created = 0;

        $rules = RecurringExpense::query()->with(['branch', 'cashBox'])
            ->where('is_active', true)
            ->where('starts_on', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->where('day_of_month', '<=', $today->day)
            ->get();

        foreach ($rules as $rule) {
            $forMonth = $today->copy()->day($rule->day_of_month)->toDateString();

            if ($rule->last_generated_for !== null && $rule->last_generated_for->toDateString() >= $forMonth) {
                continue;
            }

            $author = User::query()->find($rule->created_by);

            if ($author === null || $rule->cash_box_id === null) {
                continue;
            }

            try {
                $this->expenses->record($rule->branch, [
                    'expense_category_id' => $rule->expense_category_id,
                    'cash_box_id' => $rule->cash_box_id,
                    'amount' => (string) $rule->amount,
                    'expense_date' => $forMonth,
                    'payee' => $rule->payee,
                    'description' => trim(($rule->description ?? '').' · '.__('recurring')),
                ], $author, $rule, forceApproval: ! $rule->auto_post);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $rule->forceFill(['last_generated_for' => $forMonth])->save();
            $created++;
        }

        return $created;
    }
}

<?php

namespace App\Services\Reports;

use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Expenses paid in a period (Module 21 / 24) by category, branch or month. Head-office costs
 * split over branches count in each branch for its share.
 */
class ExpensesReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('Expenses report');
    }

    public function description(): ?string
    {
        return __('Paid expenses; costs split over branches count for each branch\'s share.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'category' => __('Category'),
            'branch' => __('Branch'),
            'month' => __('Month'),
            'category_branch' => __('Category and branch'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'branch' => [ReportColumn::text('branch', __('Branch'))],
            'month' => [ReportColumn::code('month', __('Month'))],
            'category_branch' => [ReportColumn::text('category', __('Category')), ReportColumn::text('branch', __('Branch'))],
            default => [ReportColumn::text('category', __('Category'))],
        };

        return [...$head, ReportColumn::number('count', __('Expenses')), ReportColumn::money('amount', __('Amount'))];
    }

    public function rows(ReportContext $context): array
    {
        $expenses = Expense::query()->with('allocations')
            ->where('status', ExpenseStatus::Posted)
            ->whereBetween('expense_date', [$context->from->toDateString(), $context->until->toDateString()])
            ->get();

        $categories = self::names(ExpenseCategory::class, $expenses->pluck('expense_category_id'));
        $branchNames = self::names(Branch::class, $expenses->pluck('branch_id')->merge($expenses->flatMap(fn (Expense $e) => $e->allocations->pluck('branch_id'))));
        $rows = [];

        foreach ($expenses as $expense) {
            $amount = $this->fx->convert($expense->amount, $expense->currency, $context->currency, $expense->fx_snapshot_id, $context) ?? BigDecimal::zero();

            // [branch id (0 = head office), share of the amount]
            $parts = $expense->is_allocated && $expense->allocations->isNotEmpty()
                ? $expense->allocations->map(fn ($a) => [(int) $a->branch_id, $amount->multipliedBy((string) $a->percentage)->dividedBy(100, 4, RoundingMode::HalfUp)])->all()
                : [[(int) ($expense->branch_id ?? 0), $amount]];

            foreach ($parts as [$branchId, $share]) {
                if ($context->branchIds !== null && ! in_array($branchId, $context->branchIds, true)) {
                    continue;
                }

                $key = match ($context->group) {
                    'branch' => 'b'.$branchId,
                    'month' => $expense->expense_date->format('Y-m'),
                    'category_branch' => 'c'.$expense->expense_category_id.'-b'.$branchId,
                    default => 'c'.$expense->expense_category_id,
                };

                $rows[$key] ??= [
                    'key' => $key,
                    'category' => $categories[$expense->expense_category_id] ?? '—',
                    'branch' => $branchId === 0 ? __('Head office') : ($branchNames[$branchId] ?? '—'),
                    'month' => $expense->expense_date->format('Y-m'),
                    'count' => 0,
                    'amount' => '0',
                ];
                $rows[$key]['count']++;
                $rows[$key]['amount'] = self::money(BigDecimal::of($rows[$key]['amount'])->plus($share));
            }
        }

        return array_values($rows);
    }
}

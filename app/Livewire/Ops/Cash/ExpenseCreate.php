<?php

namespace App\Livewire\Ops\Cash;

use App\Enums\ExpenseStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\CashBox;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Pay a branch expense from a box (Module 21, P4-14) with a photo of the receipt.
 * Large amounts wait for approval before the money leaves the box.
 */
#[Layout('layouts::ops')]
class ExpenseCreate extends Component
{
    use RunsStockActions;
    use WithFileUploads;

    public ?int $boxId = null;

    public ?int $categoryId = null;

    public string $amount = '';

    public string $payee = '';

    public string $description = '';

    /** @var array<int, mixed> */
    public array $receipts = [];

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('expenses.create', CurrentBranch::id()), 403);
        $this->boxId = $this->boxes()->first()?->id;
    }

    /**
     * @return Collection<int, CashBox>
     */
    private function boxes()
    {
        return CashBox::query()->where('branch_id', CurrentBranch::id())->where('is_active', true)->orderBy('type')->orderBy('currency')->get();
    }

    public function submit(ExpenseService $expenses): void
    {
        $this->validate([
            'boxId' => ['required', 'integer'],
            'categoryId' => ['required', 'integer'],
            'amount' => ['required'],
            'receipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ]);

        $box = $this->boxes()->firstWhere('id', $this->boxId);
        $branch = CurrentBranch::get();

        if ($box === null || $branch === null) {
            return;
        }

        $expense = $this->attempt(fn () => $expenses->record($branch, [
            'expense_category_id' => (int) $this->categoryId,
            'cash_box_id' => $box->id,
            'amount' => str_replace(',', '', trim((string) DigitConverter::toWestern($this->amount))),
            'payee' => $this->payee ?: null,
            'description' => $this->description ?: null,
        ], $this->user(), receipts: $this->receipts));

        if ($expense !== null) {
            $this->dispatch('toast', type: 'success', message: $expense->status === ExpenseStatus::PendingApproval
                ? __('Expense :number is waiting for approval.', ['number' => $expense->number])
                : __('Expense :number paid.', ['number' => $expense->number]));
            $this->redirectRoute('ops.cash', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.cash.expense-create', [
            'boxes' => $this->boxes(),
            'categories' => ExpenseCategory::options(),
        ])->title(__('Record an expense'));
    }
}

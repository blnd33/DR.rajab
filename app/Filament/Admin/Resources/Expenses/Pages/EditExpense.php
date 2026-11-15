<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Expenses\ExpenseForm;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Edit an expense through ExpenseService::update (a paid one is corrected in its box).
 */
class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Expense $expense */
        $expense = $this->getRecord();
        abort_unless(ExpenseResource::canEditExpense($expense), 403);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Expense $expense */
        $expense = $this->getRecord();

        return $schema->columns(['default' => 1, 'md' => 2])->components(ExpenseForm::components($expense));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Expense $expense */
        $expense = $this->getRecord();
        $allocations = $expense->allocations()->get();

        return [
            ...$data,
            'expense_date' => $expense->expense_date->toDateString(),
            'split' => $expense->is_allocated,
            'allocations' => $allocations->map(fn ($a) => ['branch_id' => $a->branch_id, 'percentage' => (string) (float) $a->percentage])->values()->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Expense $record */
        try {
            return app(ExpenseService::class)->update($record, ExpenseForm::serviceData($data), ServiceAction::user(), $data['reason'] ?? null);
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'amount') => $e->getMessage()]);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('index');
    }
}

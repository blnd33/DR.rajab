<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Enums\ExpenseStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Expenses\ExpenseForm;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Services\Expenses\ExpenseService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components(ExpenseForm::components());
    }

    protected function handleRecordCreation(array $data): Model
    {
        $receipts = array_map(
            fn (string $path) => new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true),
            array_values((array) ($data['receipts'] ?? [])),
        );

        try {
            $expense = app(ExpenseService::class)->record(
                filled($data['branch_id'] ?? null) ? Branch::query()->findOrFail($data['branch_id']) : null,
                ExpenseForm::serviceData($data),
                ServiceAction::user(),
                receipts: $receipts,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'amount') => $e->getMessage()]);
        }

        if ($expense->status === ExpenseStatus::PendingApproval) {
            Notification::make()->title(__('Expense :number is waiting for approval.', ['number' => $expense->number]))->warning()->send();
        }

        return $expense;
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('index');
    }
}

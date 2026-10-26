<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Enums\ExpenseStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseService;
use App\Services\Money\CashBoxService;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
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
        $user = ServiceAction::user();
        $headOffice = app(BranchScopeResolver::class)->branchIdsFor($user, 'expenses.create') === null;

        return $schema->columns(['default' => 1, 'md' => 2])->components([
            Select::make('branch_id')->label(__('Branch'))->native(false)
                ->options(fn () => ServiceAction::branchOptions('expenses.create'))
                ->required(! $headOffice)
                ->placeholder($headOffice ? __('Head office (no branch)') : null),
            Select::make('expense_category_id')->label(__('Category'))->options(fn () => ExpenseCategory::options())->searchable()->required()->native(false),
            Select::make('cash_box_id')->label(__('Paid from'))->required()->native(false)->live()
                ->options(fn () => app(CashBoxService::class)->options($user, 'expenses.create')),
            TextInput::make('amount')->label(fn (Get $get) => __('Amount (:currency)', ['currency' => CashBox::query()->find($get('cash_box_id'))->currency ?? '…']))
                ->numeric()->minValue(0)->required(),
            DatePicker::make('expense_date')->label(__('Date'))->native(false)->displayFormat('Y-m-d')->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required(),
            TextInput::make('payee')->label(__('Paid to'))->maxLength(150),
            Textarea::make('description')->label(__('Description'))->rows(2)->columnSpanFull(),
            Toggle::make('split')->label(__('Split over several branches'))->live()->columnSpanFull(),
            Repeater::make('allocations')->label(__('Branch shares (must add up to 100%)'))->columnSpanFull()->columns(2)
                ->visible(fn (Get $get) => (bool) $get('split'))
                ->defaultItems(2)->reorderable(false)->addActionLabel(__('Add a branch'))
                ->schema([
                    Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('expenses.view_any'))->required()->native(false),
                    TextInput::make('percentage')->label(__('Share (%)'))->numeric()->minValue(0)->maxValue(100)->required(),
                ]),
            FileUpload::make('receipts')->label(__('Receipts (photo or PDF)'))->multiple()->disk('local')->directory('tmp/expenses')
                ->acceptedFileTypes(['image/*', 'application/pdf'])->maxSize(10240)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $allocations = null;

        if ($data['split'] ?? false) {
            $allocations = [];

            foreach ($data['allocations'] ?? [] as $row) {
                $allocations[(int) $row['branch_id']] = (string) $row['percentage'];
            }
        }

        $receipts = array_map(
            fn (string $path) => new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true),
            array_values((array) ($data['receipts'] ?? [])),
        );

        try {
            $expense = app(ExpenseService::class)->record(
                filled($data['branch_id'] ?? null) ? Branch::query()->findOrFail($data['branch_id']) : null,
                [
                    'expense_category_id' => (int) $data['expense_category_id'],
                    'cash_box_id' => (int) $data['cash_box_id'],
                    'amount' => (string) $data['amount'],
                    'expense_date' => $data['expense_date'] ?? null,
                    'payee' => $data['payee'] ?? null,
                    'description' => $data['description'] ?? null,
                    'allocations' => $allocations,
                ],
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

<?php

namespace App\Filament\Admin\Resources\Expenses;

use App\Enums\ExpenseStatus;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CashBox;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Money\CashBoxService;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The expense form, shared by "Record an expense" and "Edit expense". The currency is chosen
 * first; the boxes listed are the ones holding that currency.
 */
final class ExpenseForm
{
    /**
     * @return list<mixed>
     */
    public static function components(?Expense $editing = null): array
    {
        $user = ServiceAction::user();
        $permission = $editing === null ? 'expenses.create' : 'expenses.update';
        $headOffice = app(BranchScopeResolver::class)->branchIdsFor($user, $permission) === null;
        $currencies = array_combine(config('orthotrack.currencies'), config('orthotrack.currencies'));

        return array_values(array_filter([
            Select::make('branch_id')->label(__('Station'))->native(false)
                ->options(fn () => ServiceAction::branchOptions($permission))
                ->required(! $headOffice && $editing === null)
                ->disabled($editing !== null)->dehydrated($editing === null)
                ->placeholder($headOffice ? __('Head office (no station)') : null),
            Select::make('expense_category_id')->label(__('Category'))->options(fn () => ExpenseCategory::options())->searchable()->required()->native(false),
            Select::make('currency')->label(__('Currency'))->options($currencies)->required()->native(false)->live()
                ->default(config('orthotrack.currencies')[0] ?? 'USD')
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                    if (CashBox::query()->whereKey($get('cash_box_id'))->value('currency') !== $state) {
                        $set('cash_box_id', null);
                    }
                }),
            Select::make('cash_box_id')->label(__('Paid from'))->required()->native(false)->live()
                ->options(fn (Get $get) => app(CashBoxService::class)->options($user, 'expenses.create', $get('currency') ?: null))
                ->helperText(fn (Get $get) => filled($get('currency')) && app(CashBoxService::class)->options($user, 'expenses.create', $get('currency')) === []
                    ? __('There is no :currency box you can pay from.', ['currency' => $get('currency')])
                    : null),
            TextInput::make('amount')->label(fn (Get $get) => __('Amount (:currency)', ['currency' => $get('currency') ?: '…']))
                ->numeric()->minValue(0)->required(),
            DatePicker::make('expense_date')->label(__('Date'))->native(false)->displayFormat('Y-m-d')->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required(),
            TextInput::make('payee')->label(__('Paid to'))->maxLength(150),
            Textarea::make('description')->label(__('Description'))->rows(2)->columnSpanFull(),
            Toggle::make('split')->label(__('Split over several stations'))->live()->columnSpanFull(),
            Repeater::make('allocations')->label(__('Station shares (must add up to 100%)'))->columnSpanFull()->columns(2)
                ->visible(fn (Get $get) => (bool) $get('split'))
                ->defaultItems(2)->reorderable(false)->addActionLabel(__('Add a station'))
                ->schema([
                    Select::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('expenses.view_any'))->required()->native(false),
                    TextInput::make('percentage')->label(__('Share (%)'))->numeric()->minValue(0)->maxValue(100)->required(),
                ]),
            $editing === null
                ? FileUpload::make('receipts')->label(__('Receipts (photo or PDF)'))->multiple()->disk('local')->directory('tmp/expenses')
                    ->acceptedFileTypes(['image/*', 'application/pdf'])->maxSize(10240)->columnSpanFull()
                : null,
            $editing !== null && $editing->status === ExpenseStatus::Posted
                ? Textarea::make('reason')->label(__('Why are you changing a paid expense?'))->required()->rows(2)->columnSpanFull()
                    ->helperText(__('The old payment goes back into its box and the corrected amount is paid again.'))
                : null,
        ]));
    }

    /**
     * Form values → service data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function serviceData(array $data): array
    {
        $allocations = null;

        if ($data['split'] ?? false) {
            $allocations = [];

            foreach ($data['allocations'] ?? [] as $row) {
                $allocations[(int) $row['branch_id']] = (string) $row['percentage'];
            }
        }

        return [
            'expense_category_id' => (int) $data['expense_category_id'],
            'cash_box_id' => (int) $data['cash_box_id'],
            'currency' => $data['currency'] ?? null,
            'amount' => (string) $data['amount'],
            'expense_date' => $data['expense_date'] ?? null,
            'payee' => $data['payee'] ?? null,
            'description' => $data['description'] ?? null,
            'allocations' => $allocations,
        ];
    }
}

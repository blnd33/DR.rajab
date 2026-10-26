<?php

namespace App\Filament\Admin\Resources\RecurringExpenses;

use App\Filament\Admin\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CashBox;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Services\Money\CashBoxService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monthly expenses (rent, salaries…) created on their day by the scheduler (P4-14).
 * Without "post automatically" they wait in the approvals queue.
 */
class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Recurring expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Recurring expenses');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'expenses.view_any', ['branch_id']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('expense_category_id')->label(__('Category'))->options(fn () => ExpenseCategory::options())->searchable()->required()->native(false),
            Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('expenses.recurring'))->native(false)
                ->placeholder(__('Head office (no branch)')),
            Select::make('cash_box_id')->label(__('Paid from'))->required()->native(false)->live()
                ->options(fn () => app(CashBoxService::class)->options(ServiceAction::user(), 'expenses.recurring')),
            TextInput::make('amount')->label(fn (Get $get) => __('Amount (:currency)', ['currency' => CashBox::query()->find($get('cash_box_id'))->currency ?? '…']))
                ->numeric()->minValue(0)->required(),
            TextInput::make('payee')->label(__('Paid to'))->maxLength(150),
            TextInput::make('day_of_month')->label(__('Day of the month (1–28)'))->numeric()->integer()->minValue(1)->maxValue(28)->default(1)->required(),
            DatePicker::make('starts_on')->label(__('From'))->native(false)->displayFormat('Y-m-d')->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required(),
            DatePicker::make('ends_on')->label(__('Until (optional)'))->native(false)->displayFormat('Y-m-d'),
            Textarea::make('description')->label(__('Description'))->rows(2)->columnSpanFull(),
            Toggle::make('auto_post')->label(__('Post automatically (no approval)')),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCurrency(array $data): array
    {
        $data['currency'] = CashBox::query()->findOrFail($data['cash_box_id'])->currency;
        $data['updated_by'] = ServiceAction::user()->id;

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'branch', 'cashBox.branch']))
            ->defaultSort('day_of_month')
            ->columns([
                TextColumn::make('category.name')->label(__('Category'))->description(fn (RecurringExpense $r) => $r->description)->wrap(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray')->placeholder(__('Head office')),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, RecurringExpense $r) => Display::money($state, $r->currency))
                    ->description(fn (RecurringExpense $r) => $r->cashBox?->label()),
                TextColumn::make('day_of_month')->label(__('Day'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('last_generated_for')->label(__('Last created'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state)),
                IconColumn::make('auto_post')->label(__('Automatic'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([EditAction::make()->mutateDataUsing(fn (array $data) => static::withCurrency($data))])
            ->emptyStateHeading(__('No recurring expenses'))
            ->emptyStateDescription(__('Add rent or salaries once; they are created every month on their day.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecurringExpenses::route('/')];
    }
}

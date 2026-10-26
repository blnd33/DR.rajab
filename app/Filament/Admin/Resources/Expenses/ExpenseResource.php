<?php

namespace App\Filament\Admin\Resources\Expenses;

use App\Enums\ExpenseStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money spent on running the business (Module 21, P4-13): paid from a box, optionally split
 * over branches, large ones wait for approval.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expenses');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'expenses.view_any', ['branch_id']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'category', 'cashBox.branch']))
            ->defaultSort('expense_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('expense_date')->label(__('Date'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('category.name')->label(__('Category'))->description(fn (Expense $r) => $r->description)->wrap(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray')->placeholder(__('Head office'))
                    ->description(fn (Expense $r) => $r->is_allocated ? __('split') : null),
                TextColumn::make('payee')->label(__('Paid to'))->placeholder('—')->toggleable(),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, Expense $r) => Display::money($state, $r->currency))
                    ->description(fn (Expense $r) => $r->cashBox->label()),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ExpenseStatus::options()),
                SelectFilter::make('expense_category_id')->label(__('Category'))->options(fn () => ExpenseCategory::options()),
                SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('expenses.view_any')),
            ])
            ->recordActions([
                ViewAction::make()->schema(fn (Schema $schema) => static::infolist($schema)),
                Action::make('void')->label(__('Void'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (Expense $r) => $r->status === ExpenseStatus::Posted && ServiceAction::user()->canInBranch('expenses.create', $r->branch_id))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (Expense $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(ExpenseService::class)->void($record, $data['reason'], $u), __('Expense voided — the money is back in the box.'))),
            ])
            ->emptyStateHeading(__('No expenses yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 3])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('category.name')->label(__('Category')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('amount')->label(__('Amount'))->state(fn (Expense $r) => Display::money($r->amount, $r->currency)),
                TextEntry::make('amount_usd')->label(__('In USD'))->state(fn (Expense $r) => Display::money($r->amount_usd, 'USD')),
                TextEntry::make('expense_date')->label(__('Date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextEntry::make('payee')->label(__('Paid to'))->placeholder('—'),
                TextEntry::make('requester.name')->label(__('Recorded by')),
                LocalDateTimeEntry::make('approved_at')->label(__('Approved'))->placeholder('—'),
                TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
                ViewEntry::make('receipts')->label(__('Receipts'))->view('filament.attachments.gallery')->columnSpanFull()
                    ->visible(fn (Expense $r) => $r->attachments()->exists()),
            ]),
            RepeatableEntry::make('allocations')->label(__('Split over branches'))->columnSpanFull()->columns(3)
                ->visible(fn (Expense $r) => $r->is_allocated)
                ->schema([
                    TextEntry::make('branch.name')->label(__('Branch')),
                    TextEntry::make('percentage')->label(__('Share'))->formatStateUsing(fn ($state) => Display::number($state, 2).'%'),
                    TextEntry::make('amount')->label(__('Amount'))->state(fn (ExpenseAllocation $a) => Display::money($a->amount, $a->expense->currency)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
        ];
    }
}

<?php

namespace App\Filament\Admin\Resources\BoxTransfers;

use App\Enums\BoxTransferStatus;
use App\Filament\Admin\Resources\BoxTransfers\Pages\ManageBoxTransfers;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\BoxTransfer;
use App\Models\CashBox;
use App\Services\Money\BoxTransferService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash moved between boxes / branches (Module 19).
 */
class BoxTransferResource extends Resource
{
    protected static ?string $model = BoxTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightCircle;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Cash transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cash transfers');
    }

    public static function getEloquentQuery(): Builder
    {
        $boxes = ServiceAction::scoped(CashBox::query(), 'cash_boxes.view_any', ['branch_id'])->select('id');

        return parent::getEloquentQuery()->where(fn ($q) => $q->whereIn('from_cash_box_id', $boxes)->orWhereIn('to_cash_box_id', $boxes));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', BoxTransferStatus::Sent)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromBox.branch', 'toBox.branch', 'sender']))
            ->defaultSort('sent_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('from')->label(__('From'))->state(fn (BoxTransfer $r) => $r->fromBox->label()),
                TextColumn::make('to')->label(__('To'))->state(fn (BoxTransfer $r) => $r->toBox->label()),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, BoxTransfer $r) => Display::money($state, $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge()->description(fn (BoxTransfer $r) => $r->void_reason),
                LocalDateTimeColumn::make('sent_at')->label(__('Sent')),
                TextColumn::make('sender.name')->label(__('By'))->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(BoxTransferStatus::options())])
            ->recordActions([
                Action::make('receive')->label(__('Receive'))->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                    ->visible(fn (BoxTransfer $r) => $r->status === BoxTransferStatus::Sent && ServiceAction::user()->canInBranch('cash_boxes.transfer', $r->toBox->branch_id))
                    ->requiresConfirmation()
                    ->action(fn (BoxTransfer $record, Action $action) => ServiceAction::run($action, fn ($u) => app(BoxTransferService::class)->receive($record, $u), __('Money received.'))),
                Action::make('cancel')->label(__('Cancel'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (BoxTransfer $r) => $r->status === BoxTransferStatus::Sent && ServiceAction::user()->canInBranch('cash_boxes.transfer', $r->fromBox->branch_id))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (BoxTransfer $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(BoxTransferService::class)->cancel($record, $data['reason'], $u), __('Transfer cancelled.'))),
            ])
            ->emptyStateHeading(__('No cash transfers yet'))
            ->emptyStateDescription(__('Move money from a cash box page.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageBoxTransfers::route('/')];
    }
}

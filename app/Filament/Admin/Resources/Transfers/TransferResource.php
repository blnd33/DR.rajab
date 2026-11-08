<?php

namespace App\Filament\Admin\Resources\Transfers;

use App\Enums\TransferStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\Transfers\Pages\CreateTransfer;
use App\Filament\Admin\Resources\Transfers\Pages\ListTransfers;
use App\Filament\Admin\Resources\Transfers\Pages\ViewTransfer;
use App\Filament\Admin\Resources\Transfers\RelationManagers\ItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Branch;
use App\Models\Transfer;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inter-branch transfers (Module 8).
 */
class TransferResource extends Resource
{
    protected static ?string $model = Transfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Transfers');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'transfers.view_any', ['from_branch_id', 'to_branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', TransferStatus::InTransit)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromBranch', 'toBranch'])->withSum('items', 'quantity_sent'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('route')->label(__('From → to'))->state(fn (Transfer $r) => $r->fromBranch->code.' → '.$r->toBranch->code),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('items_sum_quantity_sent')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number((int) $state)),
                LocalDateTimeColumn::make('sent_at')->label(__('Sent'))->sortable()->placeholder('—'),
                LocalDateTimeColumn::make('received_at')->label(__('Received'))->sortable()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(TransferStatus::options())->multiple(),
                SelectFilter::make('branch')->label(__('Branch'))
                    ->options(fn () => Branch::query()->orderBy('code')->pluck('code', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(fn ($w) => $w->where('from_branch_id', $data['value'])->orWhere('to_branch_id', $data['value']))
                        : $query),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No transfers yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('fromBranch.code')->label(__('From')),
                TextEntry::make('toBranch.code')->label(__('To')),
                TextEntry::make('stockRequest.number')->label(__('Stock request'))->placeholder('—')->fontFamily(FontFamily::Mono),
                TextEntry::make('sender.name')->label(__('Sent by'))->placeholder('—'),
                LocalDateTimeEntry::make('sent_at')->label(__('Sent'))->placeholder('—'),
                TextEntry::make('receiver.name')->label(__('Received by'))->placeholder('—'),
                LocalDateTimeEntry::make('received_at')->label(__('Received'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(2),
                TextEntry::make('cancel_reason')->label(__('Cancel reason'))->visible(fn (Transfer $r) => $r->cancel_reason !== null)->columnSpan(2),
            ]),
            // Our photo when sending, theirs on arrival (both required).
            Section::make(__('Photos'))->columnSpanFull()->schema([
                View::make('filament.transfers.photos'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransfers::route('/'),
            'create' => CreateTransfer::route('/create'),
            'view' => ViewTransfer::route('/{record}'),
        ];
    }
}

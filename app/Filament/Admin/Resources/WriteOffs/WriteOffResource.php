<?php

namespace App\Filament\Admin\Resources\WriteOffs;

use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\WriteOffs\Pages\CreateWriteOff;
use App\Filament\Admin\Resources\WriteOffs\Pages\ListWriteOffs;
use App\Filament\Admin\Resources\WriteOffs\Pages\ViewWriteOff;
use App\Filament\Admin\Resources\WriteOffs\RelationManagers\WriteOffItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\WriteOff;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
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
 * Damaged / lost / expired stock waiting for (or after) approval (Module 15).
 */
class WriteOffResource extends Resource
{
    protected static ?string $model = WriteOff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Write-off');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Write-offs');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'write_offs.view_any', ['branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', WriteOffStatus::Pending)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function costText(WriteOff $record): string
    {
        $parts = [];

        foreach ($record->costByCurrency() as $currency => $amount) {
            $parts[] = $currency.' '.Display::number($amount, 2);
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    public static function table(Table $table): Table
    {
        $canSeeCost = fn () => app(BranchScopeResolver::class)->hasAny(ServiceAction::user(), 'stock.view_cost');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'reporter', 'items'])->withSum('items', 'quantity'))
            ->defaultSort('reported_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('reason')->label(__('Reason'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('items_sum_quantity')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number((int) $state)),
                TextColumn::make('cost')->label(__('Cost'))->state(fn (WriteOff $r) => self::costText($r))->alignEnd()->visible($canSeeCost),
                TextColumn::make('reporter.name')->label(__('Reported by'))->toggleable(),
                LocalDateTimeColumn::make('reported_at')->label(__('Reported'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(WriteOffStatus::options())->default(WriteOffStatus::Pending->value),
                SelectFilter::make('reason')->label(__('Reason'))->options(WriteOffReason::options()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No write-offs'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('reason')->label(__('Reason'))->badge(),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('reporter.name')->label(__('Reported by')),
                LocalDateTimeEntry::make('reported_at')->label(__('Reported')),
                TextEntry::make('approver.name')->label(__('Approved by'))->placeholder('—'),
                TextEntry::make('cost')->label(__('Cost'))->state(fn (WriteOff $r) => self::costText($r)),
                TextEntry::make('note')->label(__('Note'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('rejection_reason')->label(__('Rejection reason'))->visible(fn (WriteOff $r) => $r->rejection_reason !== null)->columnSpanFull(),
                ViewEntry::make('photos')->label(__('Photos'))->view('filament.attachments.gallery')->columnSpanFull()
                    ->visible(fn (WriteOff $r) => $r->attachments()->exists()),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [WriteOffItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWriteOffs::route('/'),
            'create' => CreateWriteOff::route('/create'),
            'view' => ViewWriteOff::route('/{record}'),
        ];
    }
}

<?php

namespace App\Filament\Admin\Resources\Kits;

use App\Enums\KitStatus;
use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Resources\Kits\Pages\CreateKit;
use App\Filament\Admin\Resources\Kits\Pages\ListKits;
use App\Filament\Admin\Resources\Kits\Pages\ViewKit;
use App\Filament\Admin\Resources\Kits\RelationManagers\AssetHistoryRelationManager;
use App\Filament\Admin\Resources\Kits\RelationManagers\KitBatchesRelationManager;
use App\Filament\Admin\Resources\Kits\RelationManagers\KitCheckoutsRelationManager;
use App\Filament\Admin\Resources\Kits\RelationManagers\KitInstrumentsRelationManager;
use App\Filament\Admin\Resources\Kits\RelationManagers\KitUnitsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Kit;
use App\Models\KitType;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Physical kits (Module 18): contents, template check, prepare, check out, history.
 */
class KitResource extends Resource
{
    protected static ?string $model = Kit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Instrument set');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Instrument sets');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'kits.view_any', ['branch_id', 'home_branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::overdue(static::getEloquentQuery())->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Overdue instrument sets');
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function overdue(Builder $query): Builder
    {
        return $query->where('status', KitStatus::OutAtHospital)
            ->whereHas('currentCheckout', fn ($q) => $q->where('due_back_at', '<', now()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'branch', 'customer', 'currentCheckout'])->withCount(['units', 'instruments']))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->searchable()->sortable(),
                TextColumn::make('type.name')->label(__('Instrument set type')),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->description(fn (Kit $r) => $r->isOverdue() ? __('Overdue') : ($r->customer?->code)),
                TextColumn::make('units_count')->label(__('Implants'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('instruments_count')->label(__('Instruments'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                LocalDateTimeColumn::make('last_prepared_at')->label(__('Last prepared'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(KitStatus::options()),
                SelectFilter::make('kit_type_id')->label(__('Instrument set type'))->options(fn () => KitType::options()),
                SelectFilter::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('kits.view_any')),
                Filter::make('overdue')->label(__('Overdue only'))->query(fn (Builder $query) => static::overdue($query)),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No instrument sets yet'))
            ->emptyStateDescription(__('Create instrument set types with their template first, then the physical instrument sets.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('code')->label(__('Code'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('barcode')->label(__('Barcode'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('type.name')->label(__('Instrument set type')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('branch.code')->label(__('Where now')),
                TextEntry::make('homeBranch.code')->label(__('Home station')),
                TextEntry::make('customer.name')->label(__('At hospital'))->placeholder('—'),
                TextEntry::make('currentCheckout.number')->label(__('Checkout'))->fontFamily(FontFamily::Mono)->placeholder('—')
                    ->url(fn (Kit $r) => $r->currentCheckout !== null ? KitCheckoutResource::getUrl('view', ['record' => $r->currentCheckout]) : null)
                    ->color(fn (Kit $r) => $r->isOverdue() ? 'danger' : null)
                    ->helperText(fn (Kit $r) => $r->isOverdue() ? __('Overdue') : null),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpan(3),
                ImageEntry::make('photo_path')->label(__('Photo'))->disk('public')->visible(fn (Kit $r) => filled($r->photo_path)),
            ]),
            Section::make(__('Against the template'))->columnSpanFull()->collapsible()->schema([
                ViewEntry::make('compare')->hiddenLabel()->view('filament.kits.compare'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            KitUnitsRelationManager::class,
            KitBatchesRelationManager::class,
            KitInstrumentsRelationManager::class,
            KitCheckoutsRelationManager::class,
            AssetHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKits::route('/'),
            'create' => CreateKit::route('/create'),
            'view' => ViewKit::route('/{record}'),
        ];
    }
}

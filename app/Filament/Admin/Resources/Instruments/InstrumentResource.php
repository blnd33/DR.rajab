<?php

namespace App\Filament\Admin\Resources\Instruments;

use App\Enums\InstrumentStatus;
use App\Filament\Admin\Resources\Instruments\Pages\CreateInstrument;
use App\Filament\Admin\Resources\Instruments\Pages\EditInstrument;
use App\Filament\Admin\Resources\Instruments\Pages\ListInstruments;
use App\Filament\Admin\Resources\Instruments\Pages\ViewInstrument;
use App\Filament\Admin\Resources\Kits\KitResource;
use App\Filament\Admin\Resources\Kits\RelationManagers\AssetHistoryRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Currency;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Supplier;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable instruments (Module 18): code + barcode, branch, status, holder, kit.
 */
class InstrumentResource extends Resource
{
    protected static ?string $model = Instrument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Instrument');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Instruments');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'kits.view_any', ['branch_id']);
    }

    /**
     * Descriptive fields only; status, branch and kit change through the services.
     *
     * @return list<mixed>
     */
    public static function detailFields(): array
    {
        return [
            TextInput::make('manufacturer_serial')->label(__('Maker serial'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
            Select::make('supplier_id')->label(__('Supplier'))->options(fn () => Supplier::options())->native(false),
            TextInput::make('purchase_cost')->label(__('Purchase cost'))->numeric()->minValue(0),
            Select::make('purchase_currency')->label(__('Currency'))->options(fn () => Currency::options())->native(false),
            DatePicker::make('purchased_at')->label(__('Bought on'))->native(false)->displayFormat('Y-m-d'),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(static::detailFields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'branch', 'kit', 'holder']))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->searchable()->sortable(),
                TextColumn::make('type.name')->label(__('Instrument type')),
                CodeColumn::make('manufacturer_serial')->label(__('Maker serial'))->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('kit.code')->label(__('Kit'))->placeholder('—')
                    ->url(fn (Instrument $r) => $r->kit !== null ? KitResource::getUrl('view', ['record' => $r->kit]) : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('holder.name')->label(__('Held by'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(InstrumentStatus::options())->multiple(),
                SelectFilter::make('instrument_type_id')->label(__('Instrument type'))->options(fn () => InstrumentType::options()),
                SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('kits.view_any')),
                TernaryFilter::make('kit_id')->label(__('In a kit'))->nullable(),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No instruments yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('code')->label(__('Code'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('barcode')->label(__('Barcode'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('type.name')->label(__('Instrument type')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('kit.code')->label(__('Kit'))->placeholder('—')
                    ->url(fn (Instrument $r) => $r->kit !== null ? KitResource::getUrl('view', ['record' => $r->kit]) : null),
                TextEntry::make('holder.name')->label(__('Held by'))->placeholder('—'),
                TextEntry::make('customer.name')->label(__('At hospital'))->placeholder('—'),
                TextEntry::make('manufacturer_serial')->label(__('Maker serial'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextEntry::make('purchase')->label(__('Purchase cost'))->placeholder('—')
                    ->state(fn (Instrument $r) => $r->purchase_cost !== null ? $r->purchase_currency.' '.Display::number($r->purchase_cost, 2) : null),
                TextEntry::make('purchased_at')->label(__('Bought on'))->formatStateUsing(fn ($state) => Display::date($state))->placeholder('—'),
                TextEntry::make('supplier.name')->label(__('Supplier'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [AssetHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstruments::route('/'),
            'create' => CreateInstrument::route('/create'),
            'view' => ViewInstrument::route('/{record}'),
            'edit' => EditInstrument::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Admin\Resources\Drivers;

use App\Enums\PlateKind;
use App\Enums\PlateRegion;
use App\Filament\Admin\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Admin\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Admin\Resources\Drivers\RelationManagers\DriverTripsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Driver;
use App\Services\Agents\DriverService;
use App\Support\Locale\Display;
use App\Support\Plate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Drivers (Company agents in the side menu): the driver, the car and its Iraqi plate, and their
 * trips (arrival, received, what we paid). Kept by the super admin; never deleted.
 */
class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static ?string $slug = 'agents/drivers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Company agents');
    }

    public static function getModelLabel(): string
    {
        return __('Driver');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Drivers');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<mixed>
     */
    public static function formFields(bool $editing = false): array
    {
        $isEnglish = fn (Get $get) => $get('plate_kind') === PlateKind::English->value;
        $isArabic = fn (Get $get) => $get('plate_kind') === PlateKind::Arabic->value;

        return array_values(array_filter([
            TextInput::make('name')->label(__('Driver name'))->required()->maxLength(150),
            TextInput::make('phone')->label(__('Driver number'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('car_type')->label(__('Car type'))->maxLength(120)->placeholder(__('e.g. Toyota Hilux')),
            Toggle::make('company_car')->label(__('Our car (company car)'))->helperText(__('Off = the driver\'s own car.'))->inline(false),

            Section::make(__('Car plate number'))->columnSpanFull()->schema([
                ToggleButtons::make('plate_kind')->hiddenLabel()->inline()->required()->live()
                    ->options(PlateKind::options())->default(PlateKind::English->value),
                // Plates read left to right in every language.
                Grid::make(['default' => 3])->extraAttributes(['dir' => 'ltr'])->visible($isEnglish)->schema([
                    TextInput::make('plate_code')->label(__('Code'))->placeholder('24')->required($isEnglish)->live(onBlur: true)
                        ->length(2)->regex(Plate::CODE_PATTERN)->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric', 'class' => 'ot-code']),
                    Select::make('plate_letter')->label(__('Letter'))->required($isEnglish)->live()->native(false)->searchable()
                        ->options(array_combine(range('A', 'Z'), range('A', 'Z'))),
                    TextInput::make('plate_number')->label(__('Number'))->placeholder('3003')->required()->live(onBlur: true)
                        ->maxLength(6)->regex(Plate::NUMBER_PATTERN)->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric', 'class' => 'ot-code']),
                ]),
                Grid::make(['default' => 2])->visible($isArabic)->schema([
                    TextInput::make('plate_number_arabic')->label(__('Number'))->placeholder('12345')->required($isArabic)->live(onBlur: true)
                        ->maxLength(6)->regex(Plate::NUMBER_PATTERN)->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric', 'class' => 'ot-code']),
                    Select::make('plate_region')->label(__('Iraq or city'))->required($isArabic)->live()->native(false)->searchable()
                        ->options(PlateRegion::options()),
                ]),
                TextEntry::make('plate_preview')->label(__('The plate reads'))
                    ->state(fn (Get $get) => Plate::format($get('plate_kind'), $get('plate_code'), $get('plate_letter'),
                        $get('plate_kind') === PlateKind::Arabic->value ? $get('plate_number_arabic') : $get('plate_number'), $get('plate_region')) ?: '—')
                    ->extraAttributes(['dir' => 'ltr'])->weight('bold')->size('lg'),
            ]),

            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            $editing ? Toggle::make('is_active')->label(__('Active')) : null,
        ]));
    }

    /**
     * Form values → service data (the Arabic plate has its own number box in the form).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function serviceData(array $data): array
    {
        if (($data['plate_kind'] ?? null) === PlateKind::Arabic->value) {
            $data['plate_number'] = $data['plate_number_arabic'] ?? null;
        }

        unset($data['plate_number_arabic'], $data['plate_preview']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fill(Driver $driver): array
    {
        return [
            ...$driver->only(['name', 'phone', 'car_type', 'company_car', 'plate_code', 'plate_letter', 'plate_number', 'notes', 'is_active']),
            'plate_kind' => $driver->plate_kind->value,
            'plate_region' => $driver->plate_region?->value,
            'plate_number_arabic' => $driver->plate_number,
        ];
    }

    public static function editAction(): Action
    {
        return Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Driver $record) => static::canEdit($record))
            ->modalHeading(fn (Driver $record) => $record->name)
            ->fillForm(fn (Driver $record) => static::fill($record))
            ->schema(static::formFields(editing: true))
            ->action(fn (Driver $record, array $data, Action $action) => ServiceAction::run($action,
                fn ($u) => app(DriverService::class)->update($record, static::serviceData($data), $u), __('Saved.')));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['trips' => fn ($q) => $q->whereNull('voided_at')])
                ->withMax(['trips as last_arrival' => fn ($q) => $q->whereNull('voided_at')], 'arrived_on'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Driver name'))->searchable()->sortable()->weight('semibold'),
                CodeColumn::make('phone')->label(__('Driver number'))->placeholder('—')->searchable(),
                TextColumn::make('plate')->label(__('Car plate number'))->weight('semibold')->extraAttributes(['dir' => 'ltr'])->copyable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = (string) preg_replace('/\D+/', '', $search);

                        return $query->where(fn (Builder $q) => $q
                            ->whereRaw("plate_code || ' ' || plate_letter || ' ' || plate_number like ?", ['%'.strtoupper(trim($search)).'%'])
                            ->when($digits !== '', fn (Builder $w) => $w->orWhere('plate_number', 'like', "%{$digits}%")));
                    }),
                TextColumn::make('car_type')->label(__('Car type'))->placeholder('—')->searchable(),
                IconColumn::make('company_car')->label(__('Our car'))->boolean(),
                TextColumn::make('trips_count')->label(__('Trips'))->alignEnd()->sortable()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('last_arrival')->label(__('Last arrival'))->placeholder('—')->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active'))->default(true),
                TernaryFilter::make('company_car')->label(__('Our car')),
            ])
            ->recordActions([ViewAction::make(), static::editAction()])
            ->emptyStateHeading(__('No drivers yet'))
            ->emptyStateDescription(__('Add the drivers who bring goods; then record each trip and what you paid.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('name')->label(__('Driver name'))->weight('semibold'),
                TextEntry::make('phone')->label(__('Driver number'))->placeholder('—')->extraAttributes(['dir' => 'ltr'])->fontFamily('mono'),
                TextEntry::make('plate')->label(__('Car plate number'))->weight('bold')->extraAttributes(['dir' => 'ltr'])->copyable(),
                TextEntry::make('car_type')->label(__('Car type'))->placeholder('—'),
                IconEntry::make('company_car')->label(__('Our car'))->boolean(),
                TextEntry::make('trips')->label(__('Trips'))
                    ->state(fn (Driver $record) => Display::number($record->trips()->counted()->count())),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [DriverTripsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'view' => ViewDriver::route('/{record}'),
        ];
    }
}

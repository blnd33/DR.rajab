<?php

namespace App\Filament\Admin\Resources\Patients;

use App\Enums\Gender;
use App\Filament\Admin\Resources\Patients\Pages\CreatePatient;
use App\Filament\Admin\Resources\Patients\Pages\EditPatient;
use App\Filament\Admin\Resources\Patients\Pages\ListPatients;
use App\Filament\Admin\Resources\Patients\Pages\ViewPatient;
use App\Filament\Admin\Resources\Patients\RelationManagers\PatientSurgeriesRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\PatientService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\EditAction;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Patient files (Module 12) — sensitive. Doctors see their own patients unless granted
 * patients.view_all; every opening is written to the activity log.
 */
class PatientResource extends Resource
{
    protected static ?string $model = Patient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'file_number';

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Patient');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Patients');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('id', app(PatientService::class)->visibleTo(ServiceAction::user())->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('full_name')->label(__('Full name'))->required()->maxLength(150),
            TextInput::make('file_number')->label(__('File number'))->maxLength(32)
                ->helperText(__('Leave empty for the next number.'))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code'])
                ->hiddenOn('edit'),
            Select::make('branch_id')->label(__('Registered at'))->options(fn () => ServiceAction::branchOptions('patients.manage'))->required()->native(false)
                ->default(fn () => ServiceAction::user()->primary_branch_id)->hiddenOn('edit'),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('date_of_birth')->label(__('Date of birth'))->native(false)->displayFormat('Y-m-d')->maxDate(now()),
            TextInput::make('age')->label(__('Age (if the date is unknown)'))->numeric()->integer()->minValue(0)->maxValue(130)->dehydrated(),
            Select::make('gender')->label(__('Gender'))->options(Gender::options())->native(false),
            Select::make('primary_doctor_id')->label(__('Primary doctor'))->native(false)->searchable()
                ->options(fn () => User::doctorOptions()),
            Textarea::make('notes')->label(__('Notes (private)'))->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'primaryDoctor'])->withCount('surgeries'))
            ->defaultSort('created_at', 'desc')
            ->searchable(false)
            ->columns([
                CodeColumn::make('file_number')->label(__('File number'))->sortable(),
                TextColumn::make('full_name')->label(__('Name'))->sortable()->weight('semibold'),
                TextColumn::make('age')->label(__('Age'))->state(fn (Patient $r) => $r->age())->formatStateUsing(fn ($state) => Display::number($state))->placeholder('—'),
                TextColumn::make('gender')->label(__('Gender'))->badge()->placeholder('—')->toggleable(),
                TextColumn::make('primaryDoctor.name')->label(__('Primary doctor'))->placeholder('—')->toggleable(),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray'),
                TextColumn::make('surgeries_count')->label(__('Surgeries'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
            ])
            ->filters([
                SelectFilter::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('patients.view_all') ?: ServiceAction::branchOptions('patients.view_own')),
                Filter::make('search')
                    ->schema([TextInput::make('q')->label(__('File number, name or phone'))])
                    ->query(fn (Builder $query, array $data) => filled($data['q'] ?? null)
                        ? $query->whereIn('id', app(PatientService::class)->search(ServiceAction::user(), (string) $data['q'])->select('id'))
                        : $query),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading(__('No patients yet'))
            ->emptyStateDescription(__('Patients are added here or while logging a surgery.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('file_number')->label(__('File number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('full_name')->label(__('Name')),
                TextEntry::make('age')->label(__('Age'))->state(fn (Patient $r) => $r->age())->formatStateUsing(fn ($state) => Display::number($state))->placeholder('—'),
                TextEntry::make('gender')->label(__('Gender'))->badge()->placeholder('—'),
                TextEntry::make('phone')->label(__('Phone'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->placeholder('—'),
                TextEntry::make('date_of_birth')->label(__('Date of birth'))->formatStateUsing(fn ($state) => Display::date($state))->placeholder('—'),
                TextEntry::make('primaryDoctor.name')->label(__('Primary doctor'))->placeholder('—'),
                TextEntry::make('branch.code')->label(__('Registered at')),
                TextEntry::make('notes')->label(__('Notes (private)'))->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PatientSurgeriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'create' => CreatePatient::route('/create'),
            'view' => ViewPatient::route('/{record}'),
            'edit' => EditPatient::route('/{record}/edit'),
        ];
    }
}

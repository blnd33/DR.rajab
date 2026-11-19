<?php

namespace App\Filament\Admin\Resources\Technicians;

use App\Enums\AgentType;
use App\Enums\Role;
use App\Enums\SurgeryStatus;
use App\Filament\Admin\Resources\Agents\AgentForm;
use App\Filament\Admin\Resources\Technicians\Pages\ListTechnicians;
use App\Filament\Admin\Resources\Technicians\Pages\ViewTechnician;
use App\Filament\Admin\Resources\Technicians\RelationManagers\TechnicianSurgeriesRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\Agents\CompanyAgentService;
use App\Services\Users\TechnicianService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Technicians who work with us on surgeries (Company agents in the side menu). Their account only
 * exists so their work can be counted (surgeries, money, graphs): it never signs in and has no
 * permissions. The super admin (agents.manage) also keeps their CV, position and salary.
 */
class TechnicianResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'technicians';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Company agents');
    }

    /**
     * CV, position and salary are only for the super admin (agents.manage).
     */
    public static function canSeeProfile(): bool
    {
        return ServiceAction::user()->can('agents.manage');
    }

    /**
     * Save the technician, then their profile when the user may keep it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(?User $technician, array $data, User $by): User
    {
        $saved = DB::transaction(function () use ($technician, $data, $by) {
            $saved = $technician === null
                ? app(TechnicianService::class)->create($data, $by)
                : app(TechnicianService::class)->update($technician, $data, $by);

            if ($by->can('agents.manage')) {
                app(CompanyAgentService::class)->saveTechnicianProfile($saved, $data, $by, AgentForm::cv($data));
            }

            return $saved;
        });

        AgentForm::forgetUpload($data);

        return $saved;
    }

    public static function getModelLabel(): string
    {
        return __('Technician');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Technicians');
    }

    public static function canViewAny(): bool
    {
        $user = ServiceAction::user();

        return $user->can('technicians.manage') || $user->branchIdsFor('surgeries.view_any') !== [];
    }

    public static function canCreate(): bool
    {
        return ServiceAction::user()->can('technicians.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return ServiceAction::user()->can('technicians.manage');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $branchId = User::focusedBranchId();

        return parent::getEloquentQuery()->whereHas('roles', fn (Builder $r) => $r->where('name', Role::Technician->value))
            ->when($branchId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('primary_branch_id', $branchId)
                ->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId))));
    }

    /**
     * @return list<mixed>
     */
    public static function formFields(bool $editing = false): array
    {
        return array_values(array_filter([
            TextInput::make('name')->label(__('Full name'))->required()->maxLength(150),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('branches')->label(__('Stations'))->multiple()->required()->native(false)
                ->options(fn () => Branch::query()->active()->orderBy('code')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->label()]))
                ->default(fn () => array_values(array_filter([User::focusedBranchId() ?? ServiceAction::user()->primary_branch_id])))
                ->helperText(__('The technician is offered on surgeries of these stations.')),
            $editing ? Toggle::make('is_active')->label(__('Active'))->helperText(__('Inactive technicians are no longer offered on new surgeries. Their work is kept.')) : null,
            Section::make(__('CV, position and salary'))->columnSpanFull()->columns(2)
                ->visible(fn () => static::canSeeProfile())
                ->schema(AgentForm::details(AgentType::Technician)),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('agentProfile')->withCount(['technicianSurgeries as surgeries_count' => fn ($q) => $q->where('status', SurgeryStatus::Completed)])
                ->withMax(['technicianSurgeries as last_surgery' => fn ($q) => $q->where('status', SurgeryStatus::Completed)], 'surgery_date'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('semibold'),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('branches.code')->label(__('Stations'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('agentProfile.position')->label(__('Position'))->placeholder('—')->visible(fn () => static::canSeeProfile()),
                TextColumn::make('agentProfile.salary_amount')->label(__('Salary'))->alignEnd()->placeholder('—')->visible(fn () => static::canSeeProfile())
                    ->formatStateUsing(fn ($state, User $record) => Display::money($state, $record->agentProfile?->salary_currency)),
                TextColumn::make('surgeries_count')->label(__('Surgeries'))->alignEnd()->sortable()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('last_surgery')->label(__('Last surgery'))->placeholder('—')->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([
                ViewAction::make()->label(__('Work')),
                Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => ServiceAction::user()->can('technicians.manage'))
                    ->fillForm(fn (User $record) => [
                        ...AgentForm::fill($record->agentProfile),
                        'name' => $record->name, 'phone' => $record->phone, 'is_active' => $record->is_active, 'branches' => $record->branches()->pluck('branches.id')->all(),
                    ])
                    ->schema(static::formFields(editing: true))
                    ->action(fn (User $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => static::save($record, $data, $u), __('Technician saved.'))),
            ])
            ->emptyStateHeading(__('No technicians yet'))
            ->emptyStateDescription(__('Add the technicians who work with you; then pick them on each surgery.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('phone')->label(__('Phone'))->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('surgeries')->label(__('Surgeries'))
                    ->state(fn (User $r) => Display::number(app(TechnicianService::class)->surgeries($r)->count())),
                TextEntry::make('this_month')->label(__('This month'))
                    ->state(fn (User $r) => Display::number(app(TechnicianService::class)->surgeries($r)->where('surgery_date', '>=', now(config('orthotrack.timezone'))->startOfMonth()->toDateString())->count())),
                TextEntry::make('fees')->label(__('Surgery money'))->columnSpanFull()
                    ->state(fn (User $r) => app(TechnicianService::class)->feeTotals($r))
                    ->listWithLineBreaks()->placeholder('—'),
                Section::make(__('CV, position and salary'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])
                    ->visible(fn () => static::canSeeProfile())
                    ->schema([
                        TextEntry::make('agentProfile.position')->label(__('Position'))->placeholder('—'),
                        TextEntry::make('salary')->label(__('Salary (per month)'))->placeholder('—')
                            ->state(fn (User $record) => Display::money($record->agentProfile?->salary_amount, $record->agentProfile?->salary_currency) ?: null),
                        ViewEntry::make('cvs')->label(__('CV'))->view('filament.agents.cvs')->columnSpanFull(),
                    ]),
                TextEntry::make('note')->hiddenLabel()->columnSpanFull()->color('gray')
                    ->state(__('This account cannot sign in and has no permissions. It only counts the technician\'s work.')),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [TechnicianSurgeriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTechnicians::route('/'),
            'view' => ViewTechnician::route('/{record}'),
        ];
    }
}

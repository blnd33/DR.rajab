<?php

namespace App\Filament\Admin\Resources\Roles;

use App\Enums\Role as RoleEnum;
use App\Filament\Admin\Forms\PermissionChecklist;
use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Services\Permissions\RoleService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * Role templates. Permissions grouped by module, labelled in the user's language.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Role'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->helperText(__('Lowercase, e.g. senior_staff.'))
                            ->required()
                            ->regex('/^[a-z0-9_]+$/')
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record) => $record !== null && RoleEnum::tryFrom($record->name) !== null)
                            ->extraInputAttributes(['dir' => 'ltr']),
                        Text::make(fn (?Role $record) => $record !== null && RoleEnum::tryFrom($record->name) !== null
                            ? __('Built-in role: the name is fixed, permissions can be changed.')
                            : null),
                    ]),
                Section::make(__('Permissions'))
                    ->description(__('Changes apply to every user with this role.'))
                    ->schema([
                        PermissionChecklist::make('permissions'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label(__('Role'))
                    ->formatStateUsing(fn (string $state) => RoleService::label($state))
                    ->description(fn (Role $record) => $record->name === RoleEnum::SuperAdmin->value ? __('Has every permission') : null),
                TextColumn::make('users_count')->label(__('Users'))->numeric()->alignEnd(),
                TextColumn::make('permissions_count')->label(__('Permissions'))->numeric()->alignEnd(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}

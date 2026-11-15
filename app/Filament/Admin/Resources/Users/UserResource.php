<?php

namespace App\Filament\Admin\Resources\Users;

use App\Enums\Role as RoleEnum;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\EditUserPermissions;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\RelationManagers\DeviceSessionsRelationManager;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    /**
     * Technicians have their own screen (Clinical → Technicians). With a branch chosen in the
     * top bar, only that branch's accounts.
     */
    public static function getEloquentQuery(): Builder
    {
        $branchId = User::focusedBranchId();

        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $r) => $r->where('name', RoleEnum::Technician->value))
            // Branch chosen in the top bar: only the people who work there.
            ->when($branchId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('all_branches', true)
                ->orWhere('primary_branch_id', $branchId)
                ->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId))));
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'email', 'phone'];
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DeviceSessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
            'permissions' => EditUserPermissions::route('/{record}/permissions'),
        ];
    }
}

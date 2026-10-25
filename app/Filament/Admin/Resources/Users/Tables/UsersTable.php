<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\RoleService;
use App\Services\Users\UserService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'primaryBranch']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()
                    ->description(fn (User $record) => $record->email),
                CodeColumn::make('username')->label(__('Username'))->searchable(),
                TextColumn::make('roles.name')->label(__('Role'))->badge()
                    ->formatStateUsing(fn (string $state) => RoleService::label($state)),
                TextColumn::make('primaryBranch.code')->label(__('Main branch'))->badge()->color('gray')
                    ->placeholder(fn (User $record) => $record->all_branches ? __('All branches') : '—'),
                TextColumn::make('locale')->label(__('Language'))->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                LocalDateTimeColumn::make('last_login_at')->label(__('Last login'))->sortable()->placeholder(__('Never')),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('Role'))
                    ->options(fn () => Role::query()->get()->mapWithKeys(fn (Role $r) => [$r->name => RoleService::label($r)]))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('roles', fn ($q) => $q->where('name', $data['value']))
                        : $query),
                SelectFilter::make('branch')
                    ->label(__('Branch'))
                    ->options(fn () => Branch::query()->orderBy('code')->pluck('code', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->where(fn ($q) => $q->where('all_branches', true)->orWhereHas('branches', fn ($b) => $b->whereKey($data['value'])))
                        : $query),
                TernaryFilter::make('is_active')->label(__('Active'))->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('permissions')
                        ->label(__('Permissions'))
                        ->icon(Heroicon::OutlinedKey)
                        ->url(fn (User $record) => UserResource::getUrl('permissions', ['record' => $record]))
                        ->visible(fn (User $record) => Auth::user()?->can('managePermissions', $record)),
                    Action::make('deactivate')
                        ->label(__('Deactivate'))
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription(__('The user is logged out of all devices and cannot log in. History is kept.'))
                        ->visible(fn (User $record) => $record->is_active && Auth::user()?->can('deactivate', $record))
                        ->action(function (User $record) {
                            app(UserService::class)->deactivate($record, Auth::user());
                            Notification::make()->title(__('User deactivated.'))->success()->send();
                        }),
                    Action::make('reactivate')
                        ->label(__('Reactivate'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (User $record) => ! $record->is_active && Auth::user()?->can('deactivate', $record))
                        ->action(function (User $record) {
                            app(UserService::class)->reactivate($record, Auth::user());
                            Notification::make()->title(__('User reactivated.'))->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading(__('No users found'));
    }
}

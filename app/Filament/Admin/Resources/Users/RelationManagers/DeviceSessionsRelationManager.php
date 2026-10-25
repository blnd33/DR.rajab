<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Devices the user is logged in on — "log out this device" for lost phones.
 */
class DeviceSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'deviceSessions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Devices');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('manageSessions', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->latest('last_activity_at'))
            ->columns([
                TextColumn::make('device_label')->label(__('Device')),
                TextColumn::make('panel')->label(__('Used in'))->badge()->color('gray'),
                CodeColumn::make('ip_address')->label(__('IP address')),
                LocalDateTimeColumn::make('last_activity_at')->label(__('Last active')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (UserSession $record) => $record->isRevoked() ? __('Logged out') : __('Active'))
                    ->color(fn (UserSession $record) => $record->isRevoked() ? 'gray' : 'success')
                    ->icon(fn (UserSession $record) => $record->isRevoked() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedSignal),
            ])
            ->headerActions([
                Action::make('revokeAll')
                    ->label(__('Log out all devices'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function () {
                        /** @var User $owner */
                        $owner = $this->getOwnerRecord();
                        $count = app(SessionRegistry::class)->revokeAll($owner, Auth::user());

                        Notification::make()->title(trans_choice(':count device logged out.|:count devices logged out.', $count, ['count' => $count]))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Log out this device'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (UserSession $record) => ! $record->isRevoked())
                    ->action(function (UserSession $record) {
                        app(SessionRegistry::class)->revoke($record, Auth::user());

                        Notification::make()->title(__('Device logged out.'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No devices yet'));
    }
}

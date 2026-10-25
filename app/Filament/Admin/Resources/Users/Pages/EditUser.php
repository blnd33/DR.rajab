<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['roles'] = $user->roles->pluck('name')->all();
        $data['branches'] = $user->branches()->pluck('branches.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $data['branches'] = ($data['all_branches'] ?? false) ? [] : ($data['branches'] ?? []);

        return app(UserService::class)->update($record, $data, Auth::user());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('permissions')
                ->label(__('Permissions'))
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->url(fn () => UserResource::getUrl('permissions', ['record' => $this->getRecord()]))
                ->visible(fn () => Auth::user()?->can('managePermissions', $this->getRecord())),
        ];
    }
}

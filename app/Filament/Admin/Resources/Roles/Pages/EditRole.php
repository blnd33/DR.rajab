<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Forms\PermissionChecklist;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Services\Permissions\RoleService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $data['permissions'] = PermissionChecklist::toGroups($role->permissions->pluck('name')->all());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        return app(RoleService::class)->save($record, $data['name'] ?? $record->name, PermissionChecklist::flatten($data['permissions'] ?? []));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Forms\PermissionChecklist;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Services\Permissions\RoleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(RoleService::class)->save(null, $data['name'], PermissionChecklist::flatten($data['permissions'] ?? []));
    }
}

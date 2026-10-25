<?php

namespace App\Filament\Admin\Resources\Branches\Pages;

use App\Filament\Admin\Resources\Branches\BranchResource;
use App\Models\Branch;
use App\Services\Branches\BranchService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Branch $record */
        return app(BranchService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

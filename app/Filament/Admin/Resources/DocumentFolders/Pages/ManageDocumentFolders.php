<?php

namespace App\Filament\Admin\Resources\DocumentFolders\Pages;

use App\Filament\Admin\Resources\DocumentFolders\DocumentFolderResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Documents\CompanyDocumentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentFolders extends ManageRecords
{
    protected static string $resource = DocumentFolderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(CompanyDocumentService::class)->saveFolder(null, $data, ServiceAction::user())),
        ];
    }
}

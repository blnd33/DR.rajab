<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Enums\ImportType;
use App\Filament\Admin\Resources\DataImports\DataImportResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Services\Imports\Exporter;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->label(__('Import from Excel'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->url(DataImportResource::getUrl('create', ['type' => ImportType::Products->value]))
                    ->visible(fn () => Auth::user()->can('products.import')),
                Action::make('export')
                    ->label(__('Export to Excel'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn () => app(BranchScopeResolver::class)->hasAny(Auth::user(), 'products.export'))
                    ->action(fn () => Excel::download(app(Exporter::class)->sheet(ImportType::Products, Auth::user()), app(Exporter::class)->filename(ImportType::Products))),
            ])
                ->label(__('Excel'))
                ->icon(Heroicon::OutlinedTableCells)
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Admin\Support;

use App\Enums\ImportType;
use App\Filament\Admin\Resources\DataImports\DataImportResource;
use App\Models\User;
use App\Services\Imports\Exporter;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Excel" menu on stock screens: import / export stock and minimum stock.
 */
final class StockExcelActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::import(ImportType::Stock, __('Import stock')),
            self::export(ImportType::Stock, __('Export stock'), 'stock.export'),
            self::import(ImportType::MinStock, __('Import minimum stock')),
            self::export(ImportType::MinStock, __('Export minimum stock'), 'stock.export'),
        ])
            ->label(__('Excel'))
            ->icon(Heroicon::OutlinedTableCells)
            ->button()
            ->color('gray');
    }

    private static function import(ImportType $type, string $label): Action
    {
        return Action::make('import_'.$type->value)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->url(DataImportResource::getUrl('create', ['type' => $type->value]))
            ->visible(fn () => app(BranchScopeResolver::class)->hasAny(self::user(), $type->permission()));
    }

    private static function export(ImportType $type, string $label, string $permission): Action
    {
        return Action::make('export_'.$type->value)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn () => app(BranchScopeResolver::class)->hasAny(self::user(), $permission))
            ->action(fn () => Excel::download(app(Exporter::class)->sheet($type, self::user()), app(Exporter::class)->filename($type)));
    }

    private static function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}

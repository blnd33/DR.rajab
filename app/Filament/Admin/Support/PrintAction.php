<?php

namespace App\Filament\Admin\Support;

use App\Http\Controllers\DocumentPrintController;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * "Print / PDF" button for a document (P5-3): opens its print page in a new tab.
 */
final class PrintAction
{
    /**
     * @param  (callable(): Model)|null  $resolve  page header actions pass the page's record; table rows use their own
     */
    public static function make(string $type, ?callable $resolve = null, string $name = 'print'): Action
    {
        return Action::make($name)
            ->label(__('Print / PDF'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            // Filament injects the row by argument name: it must be `$record`.
            ->url(fn (?Model $record = null) => DocumentPrintController::url($type, $resolve !== null ? $resolve() : $record))
            ->openUrlInNewTab();
    }
}

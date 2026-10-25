<?php

namespace App\Filament\Admin\Resources\StockUnits\Pages;

use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Actions\StockCorrectionActions;
use App\Filament\Admin\Resources\StockUnits\StockUnitResource;
use App\Models\StockUnit;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewStockUnit extends ViewRecord
{
    protected static string $resource = StockUnitResource::class;

    public function getTitle(): string
    {
        /** @var StockUnit $record */
        $record = $this->getRecord();

        return __('Serial :serial', ['serial' => $record->serial]);
    }

    protected function getHeaderActions(): array
    {
        return [
            PrintLabelsAction::make('unit')->visible(fn (StockUnit $record) => Auth::user()->can('printLabel', $record)),
            StockCorrectionActions::correctUnit(),
            StockCorrectionActions::overrideStatus(),
        ];
    }
}

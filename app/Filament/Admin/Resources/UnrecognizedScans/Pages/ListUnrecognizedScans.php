<?php

namespace App\Filament\Admin\Resources\UnrecognizedScans\Pages;

use App\Filament\Admin\Resources\UnrecognizedScans\UnrecognizedScanResource;
use Filament\Resources\Pages\ListRecords;

class ListUnrecognizedScans extends ListRecords
{
    protected static string $resource = UnrecognizedScanResource::class;
}

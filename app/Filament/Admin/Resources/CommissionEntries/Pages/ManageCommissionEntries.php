<?php

namespace App\Filament\Admin\Resources\CommissionEntries\Pages;

use App\Filament\Admin\Resources\CommissionEntries\CommissionEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCommissionEntries extends ManageRecords
{
    protected static string $resource = CommissionEntryResource::class;
}

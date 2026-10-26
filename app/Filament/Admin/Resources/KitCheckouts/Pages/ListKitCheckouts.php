<?php

namespace App\Filament\Admin\Resources\KitCheckouts\Pages;

use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Kits are checked out from the kit's page; this is the list of all checkouts.
 */
class ListKitCheckouts extends ListRecords
{
    protected static string $resource = KitCheckoutResource::class;
}

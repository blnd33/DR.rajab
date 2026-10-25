<?php

namespace App\Filament\Admin\Infolists;

use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Expiry date as the configurable level chip on view pages.
 */
class ExpiryEntry extends TextEntry
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Expiry'))
            ->placeholder(__('No expiry'))
            ->formatStateUsing(fn ($state) => new HtmlString(Blade::render('<x-expiry-chip :date="$date" />', ['date' => $state])));
    }
}

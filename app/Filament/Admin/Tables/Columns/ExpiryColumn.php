<?php

namespace App\Filament\Admin\Tables\Columns;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Expiry date as the configurable level chip: color + level name + days left + date.
 */
class ExpiryColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Expiry'))
            ->placeholder(__('No expiry'))
            ->sortable()
            ->formatStateUsing(fn ($state) => new HtmlString(Blade::render('<x-expiry-chip :date="$date" />', ['date' => $state])));
    }
}

<?php

namespace App\Filament\Admin\Infolists;

use App\Support\Locale\Display;
use Filament\Infolists\Components\TextEntry;

/**
 * Infolist counterpart of LocalDateTimeColumn.
 */
class LocalDateTimeEntry extends TextEntry
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn ($state) => Display::dateTime($state));
    }
}

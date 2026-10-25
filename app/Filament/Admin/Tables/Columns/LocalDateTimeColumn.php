<?php

namespace App\Filament\Admin\Tables\Columns;

use App\Support\Locale\Display;
use Filament\Tables\Columns\TextColumn;

/**
 * "YYYY-MM-DD HH:MM" in the business timezone, digits in the user's digit system.
 * (Filament's dateTime() always prints Western digits.)
 */
class LocalDateTimeColumn extends TextColumn
{
    protected bool $withTime = true;

    public function dateOnly(bool $condition = true): static
    {
        $this->withTime = ! $condition;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn ($state) => $this->withTime ? Display::dateTime($state) : Display::date($state))
            ->extraAttributes(['dir' => 'ltr']);
    }
}

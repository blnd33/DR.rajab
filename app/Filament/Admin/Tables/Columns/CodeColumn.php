<?php

namespace App\Filament\Admin\Tables\Columns;

use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;

/**
 * Serial / lot / SKU / username / IP / document number: Western digits, LTR, monospace, copyable.
 */
class CodeColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fontFamily(FontFamily::Mono)
            ->extraAttributes(['dir' => 'ltr'])
            ->copyable();
    }
}

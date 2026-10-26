<?php

namespace App\Services\Assets;

use App\Models\Instrument;
use App\Models\Kit;
use App\Support\Locale\DigitConverter;

/**
 * Finds a kit or an instrument by its code or barcode label (scanned or typed).
 */
class AssetLookup
{
    public function find(string $raw): Kit|Instrument|null
    {
        $code = trim((string) DigitConverter::toWestern($raw));

        if ($code === '') {
            return null;
        }

        $upper = mb_strtoupper($code);

        return Kit::query()->where('barcode', $code)->orWhere('code', $upper)->first()
            ?? Instrument::query()->where('barcode', $code)->orWhere('code', $upper)->first();
    }

    public function kit(string $raw): ?Kit
    {
        $asset = $this->find($raw);

        return $asset instanceof Kit ? $asset : null;
    }

    public function instrument(string $raw): ?Instrument
    {
        $asset = $this->find($raw);

        return $asset instanceof Instrument ? $asset : null;
    }
}

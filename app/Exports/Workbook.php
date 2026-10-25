<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Several ArraySheets in one file (template = Data + Instructions).
 */
class Workbook implements WithMultipleSheets
{
    /**
     * @param  list<ArraySheet>  $sheets
     */
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}

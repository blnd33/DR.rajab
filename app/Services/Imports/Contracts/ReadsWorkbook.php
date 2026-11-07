<?php

namespace App\Services\Imports\Contracts;

/**
 * A handler whose file is not one header row + one item per row (a supplier's own
 * workbook): it reads the whole file itself and hands the pipeline ready rows.
 */
interface ReadsWorkbook
{
    /**
     * @return array<int, array<string, mixed>> row number (1…, in the order rows must be applied) → row
     */
    public function readWorkbook(string $path): array;
}

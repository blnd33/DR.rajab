<?php

namespace App\Services\Imports\Contracts;

use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\User;
use App\Services\Imports\RowResult;

/**
 * One import type (products, stock, minimum stock): its columns, how a row is checked
 * (preview with old → new) and how a confirmed row is written (through the services).
 */
interface RowHandler
{
    /**
     * column key => header text in the template (English, as in the brief).
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * Keys that must be present in the header row.
     *
     * @return list<string>
     */
    public function requiredColumns(): array;

    /**
     * Example row for the template.
     *
     * @return array<string, string|int|float>
     */
    public function example(): array;

    /**
     * Rules for the instructions sheet, already in the current locale.
     *
     * @return list<string>
     */
    public function instructions(): array;

    /**
     * Called once before rows are checked (load lookups, reset in-file duplicate tracking).
     */
    public function begin(DataImport $import, User $user): void;

    /**
     * @param  array<string, mixed>  $row  column key => raw cell
     */
    public function check(array $row, int $rowNumber): RowResult;

    /**
     * Write one confirmed row. Re-checks what may have changed since the preview and throws
     * a RowFailed with a user-facing message when it can no longer be applied.
     */
    public function apply(DataImportRow $row, DataImport $import, User $user): void;
}

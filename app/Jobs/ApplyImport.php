<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\Imports\ImportPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Write the confirmed rows of an import (progress in data_imports.processed_rows).
 */
class ApplyImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $import = DataImport::query()->find($this->importId);

        if ($import !== null) {
            $pipeline->apply($import);
        }
    }
}

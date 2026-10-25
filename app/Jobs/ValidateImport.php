<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\Imports\ImportPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Check every row of an uploaded file and store the preview.
 */
class ValidateImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $import = DataImport::query()->find($this->importId);

        if ($import !== null) {
            $pipeline->validate($import);
        }
    }
}

<?php

namespace App\Models;

use App\Enums\ImportRowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One spreadsheet row with its validation result and old → new diff.
 *
 * @property int $id
 * @property int $data_import_id
 * @property int $row_number
 * @property ImportRowAction $action
 * @property array<string, mixed> $raw
 * @property ?array<string, mixed> $normalized
 * @property ?array<string, array{old: mixed, new: mixed}> $diff
 * @property ?list<string> $errors
 * @property ?list<string> $warnings
 * @property ?Carbon $applied_at
 */
class DataImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => ImportRowAction::class,
            'raw' => 'array',
            'normalized' => 'array',
            'diff' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DataImport, $this> */
    public function dataImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class);
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}

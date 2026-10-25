<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Excel upload (Module 23): upload → validate → preview → confirm → apply.
 *
 * @property int $id
 * @property ImportType $type
 * @property int $user_id
 * @property ?int $branch_id
 * @property string $original_disk
 * @property string $original_path
 * @property string $original_filename
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $new_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $error_count
 * @property int $warning_count
 * @property int $processed_rows
 * @property ?string $error_file_path
 * @property ?string $failure_message
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 */
class DataImport extends Model
{
    protected $fillable = [
        'type',
        'user_id',
        'branch_id',
        'original_disk',
        'original_path',
        'original_filename',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'new_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'processed_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<DataImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }

    /**
     * Rows that will be (or were) written: new + updated.
     */
    public function applicableCount(): int
    {
        return $this->new_count + $this->updated_count;
    }

    public function progressPercent(): int
    {
        $total = $this->applicableCount();

        return $total === 0 ? 100 : (int) min(100, floor($this->processed_rows * 100 / $total));
    }
}

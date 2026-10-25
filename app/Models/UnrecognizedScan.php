<?php

namespace App\Models;

use App\Enums\ScanContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A code no parser could match (raw + hex, so GS/FNC1 characters stay visible).
 *
 * @property int $id
 * @property string $raw
 * @property string $raw_hex
 * @property ScanContext $context
 * @property ?int $branch_id
 * @property ?int $user_id
 * @property Carbon $created_at
 */
class UnrecognizedScan extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['raw', 'raw_hex', 'context', 'branch_id', 'user_id'];

    protected function casts(): array
    {
        return ['context' => ScanContext::class];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Raw text with control characters made visible: GS → ⟨GS⟩.
     */
    public function visibleRaw(): string
    {
        return strtr($this->raw, ["\x1D" => '⟨GS⟩', "\x1E" => '⟨RS⟩', "\x04" => '⟨EOT⟩']);
    }
}

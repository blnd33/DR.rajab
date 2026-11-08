<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Inter-branch transfer (Module 8): draft → in transit → received. Stock moves only in
 * TransferService through StockMovementService.
 *
 * @property int $id
 * @property string $number
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property TransferStatus $status
 * @property ?int $stock_request_id
 * @property ?string $notes
 * @property ?string $carrier_note
 * @property ?Carbon $sent_at
 * @property ?Carbon $received_at
 * @property ?Carbon $cancelled_at
 * @property ?string $cancel_reason
 */
class Transfer extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('transfers')->logOnly(['status', 'notes', 'cancel_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /** @return BelongsTo<StockRequest, $this> */
    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    /** @return HasMany<TransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    /** @return HasMany<TransferAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(TransferAsset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    /**
     * The photo the sending branch took, or the one the receiving branch took.
     */
    public function photo(AttachmentKind $kind): ?Attachment
    {
        return $this->relationLoaded('attachments')
            ? $this->attachments->where('kind', $kind)->last()
            : $this->attachments()->where('kind', $kind->value)->reorder()->latest('id')->first();
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity_sent');
    }

    public function hasOpenDifferences(): bool
    {
        return $this->items->contains(fn (TransferItem $i) => in_array($i->line_status, [TransferLineStatus::Missing, TransferLineStatus::Short, TransferLineStatus::Extra], true)
            && $i->resolution === TransferLineResolution::None);
    }
}

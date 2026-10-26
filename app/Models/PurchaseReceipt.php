<?php

namespace App\Models;

use App\Enums\ReceiptMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Items of a purchase received into stock (one delivery).
 *
 * @property int $id
 * @property ReceiptMethod $method
 * @property ?Carbon $received_at
 * @property string $number
 * @property int $purchase_id
 * @property int $branch_id
 */
class PurchaseReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => ReceiptMethod::class,
            'received_at' => 'datetime',
        ];
    }

    public function activityReferenceCode(): string
    {
        return (string) $this->getAttribute('number');
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasMany<PurchaseReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}

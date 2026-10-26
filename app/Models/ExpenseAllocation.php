<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Share of a general expense carried by one branch.
 *
 * @property int $id
 * @property string $percentage
 * @property string $amount
 */
class ExpenseAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

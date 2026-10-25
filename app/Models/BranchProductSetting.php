<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Minimum / maximum stock of a product in one branch (overrides the product default).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $product_id
 * @property int $min_stock
 * @property ?int $max_stock
 * @property ?int $reorder_qty
 */
class BranchProductSetting extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = ['branch_id', 'product_id', 'min_stock', 'max_stock', 'reorder_qty'];

    protected function casts(): array
    {
        return [
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'reorder_qty' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('stock')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

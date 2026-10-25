<?php

namespace App\Notifications;

use App\Models\Branch;
use App\Support\Locale\Display;

/**
 * Daily digest: products below their minimum in a branch (Module 17).
 */
class LowStockDigest extends AppNotification
{
    /**
     * @param  list<string>  $skus  a few examples for the message
     */
    public function __construct(
        public readonly int $branchId,
        public readonly int $productCount,
        public readonly array $skus,
    ) {}

    public function eventKey(): string
    {
        return 'stock.low';
    }

    public function title(): string
    {
        return __('Low stock in :branch: :count products', [
            'branch' => Branch::query()->find($this->branchId)->code ?? '',
            'count' => Display::number($this->productCount),
        ]);
    }

    public function body(): string
    {
        $examples = implode(', ', array_slice($this->skus, 0, 5));

        return $this->productCount > 5
            ? __('Below minimum: :list and more.', ['list' => $examples])
            : __('Below minimum: :list.', ['list' => $examples]);
    }

    public function url(): string
    {
        return route('ops.stock.index', ['low' => 1]);
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-trending-down';
    }
}

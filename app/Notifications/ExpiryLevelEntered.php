<?php

namespace App\Notifications;

use App\Models\Branch;
use App\Models\ExpiryLevel;
use App\Support\Locale\Display;

/**
 * Daily digest: items in a branch entered a new expiry level (only for levels with
 * "notify on enter"). One notification per branch and level, not one per unit.
 */
class ExpiryLevelEntered extends AppNotification
{
    public function __construct(
        public readonly int $branchId,
        public readonly int $levelId,
        public readonly int $quantity,
    ) {}

    public function eventKey(): string
    {
        return 'stock.expiry_level';
    }

    public function title(): string
    {
        $level = ExpiryLevel::query()->find($this->levelId);

        return __(':level: :count items in :branch', [
            'level' => $level->name ?? __('Expiry'),
            'count' => Display::number($this->quantity),
            'branch' => Branch::query()->find($this->branchId)->code ?? '',
        ]);
    }

    public function body(): string
    {
        $level = ExpiryLevel::query()->find($this->levelId);

        if ($level?->is_expired_level) {
            return __('These items are now expired and blocked from surgery, sale and transfer.');
        }

        return __('These items now expire in :range days. Use them first (FEFO).', ['range' => $level?->rangeLabel() ?? '']);
    }

    public function url(): string
    {
        return route('ops.stock.index', ['level' => $this->levelId]);
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }
}

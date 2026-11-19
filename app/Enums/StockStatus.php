<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a serial-tracked unit is in its life. Only StockMovementService changes it.
 */
enum StockStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case InStock = 'in_stock';
    case InTransit = 'in_transit';
    case InKit = 'in_kit';
    case OutAtHospital = 'out_at_hospital';
    case UsedInSurgery = 'used_in_surgery';
    case Sold = 'sold';
    case ReturnedToSupplier = 'returned_to_supplier';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case ExpiredWrittenOff = 'expired_written_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::InStock => __('In stock'),
            self::InTransit => __('In transit'),
            self::InKit => __('In an instrument set'),
            self::OutAtHospital => __('Out at hospital'),
            self::UsedInSurgery => __('Used in surgery'),
            self::Sold => __('Sold'),
            self::ReturnedToSupplier => __('Returned to supplier'),
            self::Damaged => __('Damaged'),
            self::Lost => __('Lost'),
            self::ExpiredWrittenOff => __('Expired (written off)'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::InTransit, self::InKit, self::OutAtHospital => 'info',
            self::UsedInSurgery, self::Sold => 'primary',
            self::ReturnedToSupplier => 'gray',
            self::Damaged, self::Lost, self::ExpiredWrittenOff => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::InStock => 'heroicon-m-archive-box',
            self::InTransit => 'heroicon-m-truck',
            self::InKit => 'heroicon-m-briefcase',
            self::OutAtHospital => 'heroicon-m-building-office-2',
            self::UsedInSurgery => 'heroicon-m-heart',
            self::Sold => 'heroicon-m-shopping-bag',
            self::ReturnedToSupplier => 'heroicon-m-arrow-uturn-left',
            self::Damaged => 'heroicon-m-exclamation-triangle',
            self::Lost => 'heroicon-m-question-mark-circle',
            self::ExpiredWrittenOff => 'heroicon-m-no-symbol',
        };
    }

    /**
     * Still physically ours (counts as stock on hand somewhere).
     *
     * @return list<self>
     */
    public static function onHand(): array
    {
        return [self::InStock, self::InTransit, self::InKit, self::OutAtHospital];
    }

    public function isOnHand(): bool
    {
        return in_array($this, self::onHand(), true);
    }

    public function locationType(): LocationType
    {
        return match ($this) {
            self::InStock => LocationType::Shelf,
            self::InTransit => LocationType::Transit,
            self::InKit => LocationType::Kit,
            self::OutAtHospital => LocationType::Hospital,
            default => LocationType::None,
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Role templates. The Super Admin can still customise each user's
 * permissions individually (direct permissions + branch scope).
 */
enum Role: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case SuperAdmin = 'super_admin';
    case Doctor = 'doctor';
    case Cashier = 'cashier';
    case Accountant = 'accountant';
    case BranchStaff = 'branch_staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super Admin'),
            self::Doctor => __('Doctor'),
            self::Cashier => __('Cashier'),
            self::Accountant => __('Accountant'),
            self::BranchStaff => __('Branch Staff'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Doctor => 'info',
            self::Cashier => 'warning',
            self::Accountant => 'success',
            self::BranchStaff => 'gray',
        };
    }

    /**
     * Panel a user with this role lands on after login.
     */
    public function defaultPanel(): Panel
    {
        return match ($this) {
            self::SuperAdmin, self::Accountant => Panel::Admin,
            self::Doctor, self::Cashier, self::BranchStaff => Panel::Ops,
        };
    }

    public static function tryFromName(?string $name): ?self
    {
        return $name === null ? null : self::tryFrom($name);
    }
}

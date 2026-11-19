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
    // The company's manager: every permission; give the account "All branches" to see everything.
    case Manager = 'manager';
    case Doctor = 'doctor';
    case Cashier = 'cashier';
    case Accountant = 'accountant';
    case BranchStaff = 'branch_staff';
    // A name to pick on surgeries; never signs in and holds no permissions.
    case Technician = 'technician';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super Admin'),
            self::Manager => __('Manager'),
            self::Doctor => __('Doctor'),
            self::Cashier => __('Cashier'),
            self::Accountant => __('Accountant'),
            self::BranchStaff => __('Station Staff'),
            self::Technician => __('Technician'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Manager => 'primary',
            self::Doctor => 'info',
            self::Cashier => 'warning',
            self::Accountant => 'success',
            self::BranchStaff => 'gray',
            self::Technician => 'gray',
        };
    }

    /**
     * Panel a user with this role lands on after login.
     */
    public function defaultPanel(): Panel
    {
        return match ($this) {
            self::SuperAdmin, self::Manager, self::Accountant => Panel::Admin,
            self::Doctor, self::Cashier, self::BranchStaff, self::Technician => Panel::Ops,
        };
    }

    public static function tryFromName(?string $name): ?self
    {
        return $name === null ? null : self::tryFrom($name);
    }
}

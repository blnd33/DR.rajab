<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The kinds of company agents. Each kind keeps its own information (see fields()); none of
 * them signs in. Technicians are the existing technician accounts, with a profile here.
 */
enum AgentType: string implements HasLabel
{
    use EnumHelpers;

    case Distributor = 'distributor';
    case DirectSeller = 'direct_seller';
    case Representative = 'representative';
    case Partner = 'partner';
    case Technician = 'technician';
    case Employee = 'employee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Distributor => __('Distributor'),
            self::DirectSeller => __('Direct seller'),
            self::Representative => __('Representative'),
            self::Partner => __('Partner'),
            self::Technician => __('Technician'),
            self::Employee => __('Employee'),
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Distributor => __('Distributors'),
            self::DirectSeller => __('Direct sellers'),
            self::Representative => __('Representatives'),
            self::Partner => __('Partners'),
            self::Technician => __('Technicians'),
            self::Employee => __('Employees'),
        };
    }

    /**
     * The information this kind keeps, besides name, phone, notes and active.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Distributor => ['city'],
            self::DirectSeller => ['city', 'persons', 'value', 'stock'],
            self::Representative => ['cv', 'salary', 'mission_fee'],
            self::Partner => ['city', 'shares', 'value', 'profit'],
            self::Technician => ['cv', 'position', 'salary'],
            self::Employee => ['cv', 'position', 'salary', 'user'],
        };
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->fields(), true);
    }
}

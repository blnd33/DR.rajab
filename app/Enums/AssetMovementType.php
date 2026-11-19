<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What happened to a kit or instrument (asset ledger).
 */
enum AssetMovementType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Create = 'create';
    case Prepare = 'prepare';
    case Checkout = 'checkout';
    case Return = 'return';
    case TransferSend = 'transfer_send';
    case TransferReceive = 'transfer_receive';
    case TransferCancel = 'transfer_cancel';
    case MaintenanceIn = 'maintenance_in';
    case MaintenanceOut = 'maintenance_out';
    case MarkMissing = 'mark_missing';
    case MarkDamaged = 'mark_damaged';
    case Found = 'found';
    case AssignHolder = 'assign_holder';
    case AddToKit = 'add_to_kit';
    case RemoveFromKit = 'remove_from_kit';
    case Retire = 'retire';

    public function getLabel(): string
    {
        return match ($this) {
            self::Create => __('Created'),
            self::Prepare => __('Prepared'),
            self::Checkout => __('Checked out'),
            self::Return => __('Returned'),
            self::TransferSend => __('Sent on a transfer'),
            self::TransferReceive => __('Received from a transfer'),
            self::TransferCancel => __('Transfer cancelled'),
            self::MaintenanceIn => __('Sent to maintenance'),
            self::MaintenanceOut => __('Back from maintenance'),
            self::MarkMissing => __('Marked missing'),
            self::MarkDamaged => __('Marked damaged'),
            self::Found => __('Found'),
            self::AssignHolder => __('Holder changed'),
            self::AddToKit => __('Added to an instrument set'),
            self::RemoveFromKit => __('Taken out of an instrument set'),
            self::Retire => __('Retired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Create => 'gray',
            self::Prepare => 'info',
            self::Checkout => 'warning',
            self::Return => 'success',
            self::TransferSend => 'info',
            self::TransferReceive => 'success',
            self::TransferCancel => 'gray',
            self::MaintenanceIn => 'gray',
            self::MaintenanceOut => 'success',
            self::MarkMissing => 'danger',
            self::MarkDamaged => 'danger',
            self::Found => 'success',
            self::AssignHolder => 'gray',
            self::AddToKit => 'info',
            self::RemoveFromKit => 'gray',
            self::Retire => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Create => 'heroicon-m-plus-circle',
            self::Prepare => 'heroicon-m-clipboard-document-check',
            self::Checkout => 'heroicon-m-arrow-up-tray',
            self::Return => 'heroicon-m-arrow-down-tray',
            self::TransferSend => 'heroicon-m-truck',
            self::TransferReceive => 'heroicon-m-inbox-arrow-down',
            self::TransferCancel => 'heroicon-m-arrow-uturn-left',
            self::MaintenanceIn => 'heroicon-m-wrench-screwdriver',
            self::MaintenanceOut => 'heroicon-m-check',
            self::MarkMissing => 'heroicon-m-question-mark-circle',
            self::MarkDamaged => 'heroicon-m-exclamation-triangle',
            self::Found => 'heroicon-m-check-circle',
            self::AssignHolder => 'heroicon-m-user',
            self::AddToKit => 'heroicon-m-plus',
            self::RemoveFromKit => 'heroicon-m-minus',
            self::Retire => 'heroicon-m-archive-box-x-mark',
        };
    }
}

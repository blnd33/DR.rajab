<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Every row in the stock ledger has one of these. Phase 1 writes opening_balance,
 * import, status_override and cost_adjustment; later phases add the rest.
 */
enum MovementType: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case OpeningBalance = 'opening_balance';
    case Import = 'import';
    case PurchaseReceipt = 'purchase_receipt';
    case TransferSend = 'transfer_send';
    case TransferReceive = 'transfer_receive';
    case TransferCancel = 'transfer_cancel';
    case KitLoad = 'kit_load';
    case KitUnload = 'kit_unload';
    case KitCheckout = 'kit_checkout';
    case KitReturn = 'kit_return';
    case SurgeryUse = 'surgery_use';
    case SurgeryReversal = 'surgery_reversal';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case WriteOff = 'write_off';
    case CountAdjustment = 'count_adjustment';
    case StatusOverride = 'status_override';
    case CostAdjustment = 'cost_adjustment';

    /**
     * Stock coming into the company from outside (Stock → Input).
     *
     * @return list<self>
     */
    public static function inputs(): array
    {
        return [self::OpeningBalance, self::Import, self::PurchaseReceipt, self::CustomerReturn, self::SaleReturn, self::SurgeryReversal];
    }

    /**
     * Stock leaving the company (Stock → Output). Transfers and instrument-set moves stay inside
     * the company and are neither; a count correction is input when it adds pieces, output when
     * it removes them.
     *
     * @return list<self>
     */
    public static function outputs(): array
    {
        return [self::Sale, self::SurgeryUse, self::WriteOff, self::SupplierReturn];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::OpeningBalance => __('Opening stock'),
            self::Import => __('Excel import'),
            self::PurchaseReceipt => __('Purchase received'),
            self::TransferSend => __('Transfer sent'),
            self::TransferReceive => __('Transfer received'),
            self::TransferCancel => __('Transfer cancelled'),
            self::KitLoad => __('Put in instrument set'),
            self::KitUnload => __('Taken out of instrument set'),
            self::KitCheckout => __('Instrument set sent to hospital'),
            self::KitReturn => __('Instrument set returned'),
            self::SurgeryUse => __('Used in surgery'),
            self::SurgeryReversal => __('Surgery use reversed'),
            self::Sale => __('Sold'),
            self::SaleReturn => __('Sale returned'),
            self::CustomerReturn => __('Customer return'),
            self::SupplierReturn => __('Returned to supplier'),
            self::WriteOff => __('Written off'),
            self::CountAdjustment => __('Stock count adjustment'),
            self::StatusOverride => __('Status corrected'),
            self::CostAdjustment => __('Details corrected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OpeningBalance, self::Import, self::PurchaseReceipt, self::TransferReceive,
            self::CustomerReturn, self::SaleReturn, self::SurgeryReversal, self::KitReturn => 'success',
            self::SurgeryUse, self::Sale => 'primary',
            self::WriteOff, self::SupplierReturn => 'danger',
            self::StatusOverride, self::CostAdjustment, self::CountAdjustment => 'warning',
            default => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::OpeningBalance, self::PurchaseReceipt => 'heroicon-m-inbox-arrow-down',
            self::Import => 'heroicon-m-table-cells',
            self::TransferSend, self::TransferReceive, self::TransferCancel => 'heroicon-m-truck',
            self::KitLoad, self::KitUnload, self::KitCheckout, self::KitReturn => 'heroicon-m-briefcase',
            self::SurgeryUse, self::SurgeryReversal => 'heroicon-m-heart',
            self::Sale, self::SaleReturn => 'heroicon-m-shopping-bag',
            self::CustomerReturn, self::SupplierReturn => 'heroicon-m-arrow-uturn-left',
            self::WriteOff => 'heroicon-m-trash',
            self::CountAdjustment => 'heroicon-m-clipboard-document-check',
            self::StatusOverride, self::CostAdjustment => 'heroicon-m-pencil-square',
        };
    }
}

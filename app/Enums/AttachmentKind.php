<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum AttachmentKind: string implements HasLabel
{
    use EnumHelpers;

    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case Photo = 'photo';
    case ImplantSticker = 'implant_sticker';
    case CreditNote = 'credit_note';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::Receipt => __('Receipt'),
            self::Photo => __('Photo'),
            self::ImplantSticker => __('Implant sticker'),
            self::CreditNote => __('Credit note'),
            self::Other => __('Other'),
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum NotificationChannelType: string implements HasLabel
{
    use EnumHelpers;

    case Database = 'database';
    case WebPush = 'webpush';
    case Mail = 'mail';
    case WhatsApp = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Database => __('In-app'),
            self::WebPush => __('Push notification'),
            self::Mail => __('Email'),
            self::WhatsApp => __('WhatsApp'),
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case Previewed = 'previewed';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uploaded => __('Uploaded'),
            self::Validating => __('Checking'),
            self::Previewed => __('Ready to confirm'),
            self::Confirmed => __('Confirmed'),
            self::Processing => __('Importing'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uploaded, self::Validating, self::Confirmed, self::Processing => 'info',
            self::Previewed => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function isRunning(): bool
    {
        return in_array($this, [self::Uploaded, self::Validating, self::Confirmed, self::Processing], true);
    }
}

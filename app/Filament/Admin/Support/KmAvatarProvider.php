<?php

namespace App\Filament\Admin\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * The profile picture in the admin top bar is the KM (KurdishMedicus) badge for everyone,
 * instead of the person's initials from an outside avatar service.
 */
class KmAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return asset('icons/km-avatar.svg');
    }
}

<?php

namespace App\Filament\Admin\Auth;

use App\Support\Locale\LocaleRegistry;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Profile: name, email, language, password. Two-factor setup is added by Filament.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                TextInput::make('username')
                    ->label(__('Username'))
                    ->disabled()
                    ->dehydrated(false)
                    ->extraInputAttributes(['dir' => 'ltr']),
                $this->getEmailFormComponent(),
                $this->getLocaleFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->maxLength(191)
            ->unique(ignoreRecord: true)
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label(__('Language'))
            ->options(fn () => app(LocaleRegistry::class)->options())
            ->required()
            ->native(false);
    }

    protected function getRedirectUrl(): ?string
    {
        // Reload so a language change applies (direction, fonts, digits).
        return static::getUrl();
    }
}

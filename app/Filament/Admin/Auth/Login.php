<?php

namespace App\Filament\Admin\Auth;

use App\Enums\Panel;
use App\Models\User;
use App\Services\Auth\LoginAttemptLogger;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The single login page for both panels. Accepts username or email; any
 * active user with access to at least one panel may sign in, and the
 * LoginResponse sends them to /admin or /app.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label(__('Username or email'))
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['dir' => 'ltr', 'autocapitalize' => 'none', 'spellcheck' => 'false']);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = mb_strtolower(trim((string) $data['login']));
        $field = str_contains($login, '@') ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $data['password'],
        ];
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User
            && $user->is_active
            && ($user->canAccessAppPanel(Panel::Admin) || $user->canAccessAppPanel(Panel::Ops));
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        app(LoginAttemptLogger::class)->recordThrottled($this->data['login'] ?? null);

        return parent::getRateLimitedNotification($exception);
    }
}

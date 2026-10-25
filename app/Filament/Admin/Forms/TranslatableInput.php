<?php

namespace App\Filament\Admin\Forms;

use App\Support\Locale\LocaleRegistry;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Database\Eloquent\Model;

/**
 * All languages side by side (English required, others optional) for a
 * spatie/laravel-translatable attribute. Clearer for non-technical admins than
 * a hidden locale switcher.
 *
 *   TranslatableInput::make('name', __('Name'))
 */
final class TranslatableInput
{
    public static function make(string $attribute, string $label, bool $required = true, bool $multiline = false, int $maxLength = 191): Fieldset
    {
        $locales = app(LocaleRegistry::class);

        $fields = [];

        foreach ($locales->all() as $code => $locale) {
            $field = $multiline ? Textarea::make($code)->rows(2) : TextInput::make($code)->maxLength($maxLength);

            $fields[] = $field
                ->label($locale['native'])
                ->required($required && $locale['required_for_translations'])
                ->extraInputAttributes(['dir' => $locale['dir'], 'lang' => $code]);
        }

        return Fieldset::make($label)
            ->statePath($attribute)
            ->columns(['default' => 1, 'md' => count($fields)])
            ->schema($fields)
            ->afterStateHydrated(function (Fieldset $component, ?Model $record) use ($attribute) {
                if ($record !== null && method_exists($record, 'getTranslations')) {
                    $component->state($record->getTranslations($attribute));
                }
            });
        // Blank languages are dropped by the model (HasCleanTranslations).
    }
}

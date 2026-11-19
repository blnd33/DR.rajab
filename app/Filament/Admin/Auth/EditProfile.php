<?php

namespace App\Filament\Admin\Auth;

use App\Models\User;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use App\Support\Locale\LocaleRegistry;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;

/**
 * Profile: name, email, language, password, and (for people who receive it) the morning
 * summary settings. Two-factor setup is added by Filament.
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
                $this->getSummaryFormComponent(),
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

    protected function getSummaryFormComponent(): Component
    {
        return Section::make(__('Daily Business Report'))
            ->description(__('A short report of yesterday and what needs attention today, in your bell and as a phone notification.'))
            ->visible(fn () => $this->user() !== null && MorningSummaryService::canReceive($this->user()))
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                Toggle::make('summary.enabled')->label(__('Send me the Daily Business Report'))->columnSpanFull(),
                TextInput::make('summary.time')->label(__('Time'))->type('time')->required()
                    ->helperText(__('Business time (Baghdad).'))
                    ->extraInputAttributes(['dir' => 'ltr']),
                Select::make('summary.branch_id')->label(__('Station'))
                    ->placeholder(__('All my stations'))
                    ->options(fn () => SummarySettings::branchOptions($this->user()))
                    ->native(false),
                CheckboxList::make('summary.sections')->label(__('Sections'))
                    ->options(fn () => SummarySettings::sectionOptions())
                    ->columns(2),
                Toggle::make('summary.skip_fridays')->label(__('Not on Fridays')),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->user();

        if ($user !== null) {
            $data['summary'] = SummarySettings::for($user)->toArray();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $summary = $data['summary'] ?? null;
        unset($data['summary']);

        $record = parent::handleRecordUpdate($record, $data);

        if (is_array($summary) && $record instanceof User && MorningSummaryService::canReceive($record)) {
            $summary['time'] = substr((string) ($summary['time'] ?? ''), 0, 5);
            SummarySettings::save($record, $summary);
        }

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        // Reload so a language change applies (direction, fonts, digits).
        return static::getUrl();
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}

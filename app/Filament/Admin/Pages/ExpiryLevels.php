<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Models\ExpiryLevel;
use App\Models\User;
use App\Services\Expiry\ExpiryLevelService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configurable expiry levels (Module 6): day ranges, colors, labels in every language.
 * "Expired" always exists (≤ 0 days, blocks usage); the rest must have no gaps or overlaps.
 *
 * @property-read Schema $form
 */
class ExpiryLevels extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 81;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('Expiry levels');
    }

    public function getTitle(): string
    {
        return __('Expiry levels');
    }

    public function getSubheading(): ?string
    {
        return __('Colors and day ranges used in every stock list, alert and report. The label and days left are always shown next to the color.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('settings.expiry_levels');
    }

    public function mount(): void
    {
        $levels = ExpiryLevel::query()->orderByDesc('is_expired_level')->orderBy('min_days')->get();

        $this->form->fill([
            'levels' => $levels->mapWithKeys(fn (ExpiryLevel $level) => [(string) Str::uuid() => [
                'id' => $level->id,
                'name' => $level->getTranslations('name'),
                'min_days' => $level->min_days,
                'max_days' => $level->max_days,
                'color' => $level->color,
                'is_expired_level' => $level->is_expired_level,
                'blocks_usage' => $level->blocks_usage,
                'notify_on_enter' => $level->notify_on_enter,
            ]])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Repeater::make('levels')
                    ->hiddenLabel()
                    ->reorderable(false)
                    ->addActionLabel(__('Add a level'))
                    ->itemLabel(fn (array $state) => ($state['name']['en'] ?? __('New level')).' — '.$this->rangeText($state))
                    ->deleteAction(fn (Action $action) => $action->hidden(
                        fn (array $arguments, Repeater $component) => (bool) ($component->getRawItemState($arguments['item'])['is_expired_level'] ?? false)
                    ))
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('is_expired_level')->default(false),
                        TranslatableInput::make('name', __('Label')),
                        Grid::make(['default' => 2, 'md' => 4])->schema([
                            TextInput::make('min_days')
                                ->label(__('From day'))
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->disabled(fn (Get $get) => (bool) $get('is_expired_level'))
                                ->placeholder(fn (Get $get) => $get('is_expired_level') ? '—' : null),
                            TextInput::make('max_days')
                                ->label(__('To day'))
                                ->numeric()
                                ->integer()
                                ->live(onBlur: true)
                                ->helperText(fn (Get $get) => $get('is_expired_level') ? __('Fixed: 0 days or less.') : __('Empty on the last level = "and more".'))
                                ->disabled(fn (Get $get) => (bool) $get('is_expired_level')),
                            ColorPicker::make('color')
                                ->label(__('Color'))
                                ->required()
                                ->live()
                                ->regex('/^#[0-9A-Fa-f]{6}$/'),
                            Placeholder::make('preview')
                                ->label(__('Preview'))
                                ->content(fn (Get $get) => $this->previewChip($get('color'), $get('name.'.app()->getLocale()) ?: $get('name.en'))),
                        ]),
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            Toggle::make('blocks_usage')
                                ->label(__('Block surgery, sale and transfer'))
                                ->helperText(__('Super Admin can still override with a reason (logged).'))
                                ->disabled(fn (Get $get) => (bool) $get('is_expired_level')),
                            Toggle::make('notify_on_enter')
                                ->label(__('Notify when items enter this level')),
                        ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save levels'))->submit('save')->keyBindings(['mod+s']),
                        Action::make('defaults')
                            ->label(__('Restore defaults'))
                            ->color('gray')
                            ->requiresConfirmation()
                            ->modalDescription(__('Replace all levels with Expired · Critical (1–90) · Warning (91–180) · Attention (181–365) · Good (366+).'))
                            ->action(function (ExpiryLevelService $service) {
                                $service->saveAll(ExpiryLevelService::defaults());
                                $this->mount();
                                Notification::make()->title(__('Default levels restored.'))->success()->send();
                            }),
                    ]),
                ]),
        ]);
    }

    public function save(ExpiryLevelService $service): void
    {
        $state = $this->form->getState();

        // Disabled fields are not dehydrated; the expired rule is fixed anyway.
        $levels = array_map(fn (array $level) => $level + ['min_days' => null, 'max_days' => null, 'blocks_usage' => false], array_values($state['levels'] ?? []));

        try {
            $service->saveAll($levels);
        } catch (ValidationException $e) {
            Notification::make()
                ->title(__('The levels were not saved'))
                ->body(implode("\n", array_merge(...array_values($e->errors()))))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->mount();

        Notification::make()->title(__('Expiry levels saved.'))->success()->send();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function rangeText(array $state): string
    {
        if ($state['is_expired_level'] ?? false) {
            return '≤ 0';
        }

        $min = $state['min_days'] ?? null;
        $max = $state['max_days'] ?? null;

        return match (true) {
            blank($min) => '?',
            blank($max) => $min.'+',
            default => $min.'–'.$max,
        };
    }

    private function previewChip(?string $color, ?string $label): HtmlString
    {
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $color) ? strtoupper((string) $color) : '#E2E8F0';
        $text = ExpiryLevelService::textColorFor($color);

        return new HtmlString('<span class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background-color:'.e($color).';color:'.e($text).'">'.e($label ?: __('Label')).'</span>');
    }
}

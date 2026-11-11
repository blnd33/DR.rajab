<?php

namespace App\Filament\Admin\Pages;

use App\Livewire\Concerns\ChatsQuickAnswers;
use App\Models\User;
use App\Services\Insight\QuickAnswers;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Insight → Quick answers: ready-made questions and a box for any code (no AI).
 */
class QuickAnswersPage extends Page
{
    use ChatsQuickAnswers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.quick-answers';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'quick-answers';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Insight');
    }

    public static function getNavigationLabel(): string
    {
        return __('Quick answers');
    }

    public function getTitle(): string
    {
        return __('Quick answers');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && QuickAnswers::canUse($user);
    }

    protected function onPhone(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return $this->chatViewData();
    }
}

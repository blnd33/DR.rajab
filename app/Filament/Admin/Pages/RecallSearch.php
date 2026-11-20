<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Stock\RecallSearch as RecallSearchService;
use App\Support\Locale\DigitConverter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Recall by lot (Module 4): every unit and lot bucket with that lot, where it is now.
 * From Phase 3 the surgery and patient of used units are listed too.
 */
class RecallSearch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 240;

    protected string $view = 'filament.pages.recall-search';

    #[Url]
    public string $lot = '';

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getNavigationLabel(): string
    {
        return __('Recall search');
    }

    public function getTitle(): string
    {
        return __('Recall search');
    }

    public function getSubheading(): ?string
    {
        return __('Type or scan a lot number to find every item from that lot — in stock, at hospitals, used or sold.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('stock.recall');
    }

    public function search(): void
    {
        $this->lot = trim((string) DigitConverter::toWestern($this->lot));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $results = app(RecallSearchService::class)->search($this->lot);

        return [
            'results' => $results,
            'searched' => $this->lot !== '',
            'statusCounts' => $results['units']->groupBy(fn ($u) => $u->status->value)->map->count(),
        ];
    }
}

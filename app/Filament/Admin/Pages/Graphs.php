<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Graphs\Graph;
use App\Services\Graphs\GraphRegistry;
use App\Services\Graphs\GraphSection;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Every graph of the system in one searchable list (sales, surgeries, items, money,
 * transfers, kits). Opening one shows it with its filters and a table of the values.
 */
class Graphs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'graphs';

    protected string $view = 'filament.pages.graphs';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $section = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && GraphRegistry::canUse($user);
    }

    public static function getNavigationLabel(): string
    {
        return __('Graphs');
    }

    public function getTitle(): string
    {
        return __('Graphs');
    }

    public function getSubheading(): ?string
    {
        return __('Every part of the system as a chart. Search for what you want to see, then open it.');
    }

    public function chooseSection(string $section): void
    {
        $this->section = $this->section === $section ? '' : $section;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $registry = app(GraphRegistry::class);
        $section = GraphSection::tryFrom($this->section);
        $graphs = $registry->search($user, mb_substr($this->search, 0, 100), $section);
        $visible = $registry->visibleTo($user);

        return [
            'graphs' => $graphs,
            'sections' => array_values(array_filter(GraphSection::cases(), fn (GraphSection $s) => collect($visible)->contains(fn (Graph $g) => $g->section === $s))),
            'current' => $section,
            'total' => count($visible),
        ];
    }
}

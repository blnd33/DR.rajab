<?php

namespace App\Filament\Admin\Pages;

use App\Support\SectionRegistry;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * A section's home page (Company Agents, Stock, Finance…): a window for everything in the section,
 * the same entries as under the section in the side menu (which stays as it is). Opened by
 * clicking the section's name in the menu; shows only what the user may open.
 */
class SectionPage extends Page
{
    protected string $view = 'filament.pages.section';

    protected static ?string $slug = 'section';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $section = '';

    public function mount(): void
    {
        abort_if(SectionRegistry::label($this->section) === null || $this->items() === [], 404);
    }

    public function getTitle(): string
    {
        // Every word with a capital letter, like the side menu ("Company Agents").
        return (string) preg_replace_callback('/(?<![\p{L}\p{N}\'’])\p{Ll}/u', fn (array $m) => mb_strtoupper($m[0]), (string) SectionRegistry::label($this->section));
    }

    /**
     * The section's entries, as in the side menu.
     *
     * @return list<array{label: string, url: string, icon: mixed, badge: mixed}>
     */
    public function items(): array
    {
        $label = SectionRegistry::label($this->section);
        $group = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
            ->first(fn (NavigationGroup $g) => (string) $g->getLabel() === $label);

        if ($group === null) {
            return [];
        }

        $items = [];

        // The section's own entries; what is under an entry (Catalogues → Trauma → Mikron) is
        // reached through that entry.
        foreach ($group->getItems() as $item) {
            /** @var NavigationItem $item */
            if (! $item->isVisible() || blank($item->getUrl())) {
                continue;
            }

            $items[] = [
                'label' => (string) $item->getLabel(),
                'url' => (string) $item->getUrl(),
                'icon' => $item->getIcon() ?? $group->getIcon() ?? 'heroicon-o-squares-2x2',
                'badge' => $item->getBadge(),
            ];
        }

        return $items;
    }
}

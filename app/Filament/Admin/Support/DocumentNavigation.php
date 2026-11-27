<?php

namespace App\Filament\Admin\Support;

use App\Enums\DocumentSection;
use App\Enums\Specialty;
use App\Filament\Admin\Pages\AllCataloguesPage;
use App\Filament\Admin\Pages\CataloguesPage;
use App\Filament\Admin\Pages\DocumentFolderPage;
use App\Models\DocumentFolder;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The document folders as side-menu entries, read from the database on every admin request:
 * Catalogues → Trauma → Mikron, Shipment documents → AWB, Finance → Legal / Regulatory department…
 * A folder the super admin adds appears at once.
 */
final class DocumentNavigation
{
    /** Finance already has entries; the folders come after them. */
    private const SORT_OFFSET = 100;

    /**
     * @return list<NavigationItem>
     */
    public static function items(): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->can('documents.view')) {
            return [];
        }

        try {
            $folders = DocumentFolder::query()->active()->orderBy('section')->orderBy('sort')->orderBy('id')->get();
        } catch (\Throwable $e) {
            // E.g. before the migration has run: the rest of the menu still works.
            Log::warning('Document folders could not be read for the menu', ['error' => $e->getMessage()]);

            return [];
        }

        [$catalogues, $others] = $folders->partition(fn (DocumentFolder $f) => $f->section === DocumentSection::Catalogues);

        $items = $others->map(fn (DocumentFolder $folder) => NavigationItem::make('document-folder-'.$folder->id)
            ->label((string) $folder->name)
            ->group($folder->section->getLabel())
            ->icon($folder->section === DocumentSection::Finance ? 'heroicon-o-folder' : 'heroicon-o-folder-open')
            ->sort(self::SORT_OFFSET + $folder->sort)
            ->url(DocumentFolderPage::getUrl(['folder' => $folder->id]))
            ->isActiveWhen(fn () => self::onFolder($folder->id)))
            ->values()->all();

        return [...$items, ...self::catalogues($catalogues)];
    }

    /**
     * Catalogues: the four categories (Arthroplasty, Trauma, Spine, Sport medicine), each with its
     * companies under it, then All catalogues, then companies not sorted into a category yet.
     *
     * @param  iterable<DocumentFolder>  $folders
     * @return list<NavigationItem>
     */
    private static function catalogues(iterable $folders): array
    {
        $group = DocumentSection::Catalogues->getLabel();
        $folders = collect($folders);
        $items = [];

        foreach (Specialty::cases() as $i => $specialty) {
            $mine = $folders->filter(fn (DocumentFolder $f) => $f->specialty === $specialty);

            $items[] = NavigationItem::make('catalogues-'.$specialty->value)
                ->label($specialty->getLabel())
                ->group($group)
                ->icon($specialty->icon())
                ->sort($i + 1)
                ->url(CataloguesPage::getUrl(['specialty' => $specialty->value]))
                ->isActiveWhen(fn () => (request()->routeIs(CataloguesPage::getRouteName()) && request()->query('specialty') === $specialty->value)
                    || $mine->contains(fn (DocumentFolder $f) => self::onFolder($f->id)));

            foreach ($mine as $folder) {
                $items[] = NavigationItem::make('document-folder-'.$folder->id)
                    ->label((string) $folder->name)
                    ->group($group)
                    ->parentItem($specialty->getLabel())
                    ->sort($folder->sort)
                    ->url(DocumentFolderPage::getUrl(['folder' => $folder->id]))
                    ->isActiveWhen(fn () => self::onFolder($folder->id));
            }
        }

        $items[] = NavigationItem::make('catalogues-all')
            ->label(__('All catalogues'))
            ->group($group)
            ->icon('heroicon-o-rectangle-stack')
            ->sort(10)
            ->url(AllCataloguesPage::getUrl())
            ->isActiveWhen(fn () => request()->routeIs(AllCataloguesPage::getRouteName()));

        foreach ($folders->filter(fn (DocumentFolder $f) => $f->specialty === null) as $folder) {
            $items[] = NavigationItem::make('document-folder-'.$folder->id)
                ->label((string) $folder->name)
                ->group($group)
                ->icon('heroicon-o-folder-open')
                ->sort(20 + $folder->sort)
                ->url(DocumentFolderPage::getUrl(['folder' => $folder->id]))
                ->isActiveWhen(fn () => self::onFolder($folder->id));
        }

        return $items;
    }

    private static function onFolder(int $id): bool
    {
        return request()->routeIs(DocumentFolderPage::getRouteName()) && (int) request()->query('folder') === $id;
    }
}

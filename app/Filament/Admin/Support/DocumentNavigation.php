<?php

namespace App\Filament\Admin\Support;

use App\Enums\DocumentSection;
use App\Filament\Admin\Pages\DocumentFolderPage;
use App\Models\DocumentFolder;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The document folders as side-menu entries, read from the database on every admin request:
 * Catalogues → Lima, Shipment documents → AWB, Finance → Legal / Regulatory department…
 * A folder the super admin adds appears at once.
 */
final class DocumentNavigation
{
    /** Finance already has entries; the folders come after them. */
    private const SORT_OFFSET = 100;

    /** Catalogues & Pricelist → Catalogues, after Pricelist. */
    private const CATALOGUES_SORT = 20;

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

        $items = $folders->map(fn (DocumentFolder $folder) => NavigationItem::make('document-folder-'.$folder->id)
            ->label((string) $folder->name)
            ->group($folder->section === DocumentSection::Catalogues ? __('Catalogues & Pricelist') : $folder->section->getLabel())
            // Catalogues are in the "Catalogues & Pricelist" section, with Lima, Mikron… under them.
            ->parentItem($folder->section === DocumentSection::Catalogues ? __('Catalogues') : null)
            // Under "Catalogues" the parent has the icon (a menu item and its children cannot both have one).
            ->icon(match ($folder->section) {
                DocumentSection::Catalogues => null,
                DocumentSection::Finance => 'heroicon-o-folder',
                default => 'heroicon-o-folder-open',
            })
            ->sort(self::SORT_OFFSET + $folder->sort)
            ->url(DocumentFolderPage::getUrl(['folder' => $folder->id]))
            ->isActiveWhen(fn () => request()->routeIs(DocumentFolderPage::getRouteName()) && (int) request()->query('folder') === $folder->id))
            ->values();

        $first = $folders->first(fn (DocumentFolder $f) => $f->section === DocumentSection::Catalogues);

        if ($first !== null) {
            $items->prepend(NavigationItem::make('catalogues')
                ->label(__('Catalogues'))
                ->group(__('Catalogues & Pricelist'))
                ->icon(DocumentSection::Catalogues->icon())
                ->sort(self::CATALOGUES_SORT)
                ->url(DocumentFolderPage::getUrl(['folder' => $first->id]))
                ->isActiveWhen(fn () => false));
        }

        return $items->all();
    }
}

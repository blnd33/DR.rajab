<?php

namespace App\Filament\Admin\Pages;

use App\Enums\DocumentSection;
use App\Enums\Specialty;
use App\Models\DocumentFolder;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Catalogues → a category (Arthroplasty, Trauma, Spine, Sport medicine): a window for each
 * company with catalogues in it (Lima, Mikron…); a company opens its catalogue documents.
 */
class CataloguesPage extends Page
{
    protected string $view = 'filament.pages.catalogues';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $specialty = '';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'catalogues';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('documents.view');
    }

    public function mount(): void
    {
        abort_if(Specialty::tryFrom($this->specialty) === null, 404);
    }

    public function getTitle(): string
    {
        return Specialty::from($this->specialty)->getLabel();
    }

    public function getSubheading(): ?string
    {
        return __('Catalogues');
    }

    /**
     * The companies with catalogues in this category, with how many documents each has.
     *
     * @return list<array{name: string, url: string, documents: int}>
     */
    public function companies(): array
    {
        return DocumentFolder::query()->active()
            ->where('section', DocumentSection::Catalogues->value)
            ->where('specialty', $this->specialty)
            ->withCount(['documents' => fn ($q) => $q->whereNull('archived_at')])
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (DocumentFolder $f) => [
                'name' => (string) $f->name,
                'url' => DocumentFolderPage::getUrl(['folder' => $f->id]),
                'documents' => (int) $f->documents_count,
            ])->all();
    }
}

<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Specialty;
use App\Filament\Admin\Pages\Reports\PriceListReportPage;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\PriceListReport;
use App\Services\Reports\ReportRegistry;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Pricelist → a category (Arthroplasty, Trauma, Spine, Sport medicine): a window for each company
 * with items in it; a company opens its price list (name and price of each item).
 */
class PricelistPage extends Page
{
    protected string $view = 'filament.pages.pricelist';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $specialty = '';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'pricelist';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ReportRegistry::canOpen($user, app(PriceListReport::class));
    }

    /**
     * Pricelist in the side menu: the four categories (then All companies, from its own page).
     */
    public static function registerNavigationItems(): void
    {
        if (! static::canAccess()) {
            return;
        }

        $items = [];

        foreach (Specialty::cases() as $i => $specialty) {
            $items[] = NavigationItem::make('pricelist-'.$specialty->value)
                ->label($specialty->getLabel())
                ->group(__('Pricelist'))
                ->icon($specialty->icon())
                ->sort($i + 1)
                ->url(static::getUrl(['specialty' => $specialty->value]))
                ->isActiveWhen(fn () => (request()->routeIs(static::getRouteName()) && request()->query('specialty') === $specialty->value)
                    || PriceListReportPage::inCategory($specialty));
        }

        Filament::getCurrentOrDefaultPanel()->navigationItems($items);
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
        return __('Pricelist');
    }

    /**
     * The companies with active items in this category: name, number of items, and the link to
     * their price list. Items without a company come last.
     *
     * @return list<array{name: string, url: string, items: int, priced: int}>
     */
    public function companies(): array
    {
        $counts = Product::query()->where('is_active', true)
            ->whereIn('category_id', PriceListReport::categoriesOf($this->specialty))
            ->selectRaw('brand_id, COUNT(*) AS items, COUNT(selling_price) AS priced')->groupBy('brand_id')->toBase()->get()->keyBy('brand_id');

        $names = Brand::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');
        $url = fn (int $brand) => PriceListReportPage::getUrl(['specialty' => $this->specialty, 'brand' => $brand]);

        return $counts
            ->map(fn ($row, $brand) => [
                'name' => $brand ? (string) ($names[$brand] ?? '—') : __('Without company'),
                'url' => $url((int) $brand),
                'items' => (int) $row->items,
                'priced' => (int) $row->priced,
                'last' => ! $brand,
            ])
            ->sortBy(fn (array $c) => [$c['last'], mb_strtolower($c['name'])])
            ->map(fn (array $c) => ['name' => $c['name'], 'url' => $c['url'], 'items' => $c['items'], 'priced' => $c['priced']])
            ->values()->all();
    }

    public function allUrl(): string
    {
        return PriceListReportPage::getUrl(['specialty' => $this->specialty]);
    }
}

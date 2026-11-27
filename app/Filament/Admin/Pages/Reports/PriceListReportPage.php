<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\Specialty;
use App\Models\Brand;
use App\Services\Reports\PriceListReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Pricelist → All companies (every item), or one category / one company opened from
 * Pricelist → Trauma → Mikron: the items with their company, cost, selling price and profit.
 */
class PriceListReportPage extends ReportPage
{
    protected static string $reportClass = PriceListReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // After the four categories.
    protected static ?int $navigationSort = 10;

    /** One category (from Pricelist → a category). */
    #[Url]
    public ?string $specialty = null;

    /** One company (brand id; 0 = items without a company). */
    #[Url]
    public ?int $brand = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Pricelist');
    }

    public static function getNavigationLabel(): string
    {
        return __('All companies');
    }

    public function getTitle(): string
    {
        $specialty = Specialty::tryFrom((string) $this->specialty);

        return match (true) {
            $this->brand === 0 => __('Without company'),
            $this->brand !== null => (string) (Brand::query()->whereKey($this->brand)->value('name') ?? __('Pricelist')),
            $specialty !== null => $specialty->getLabel(),
            default => __('All companies'),
        };
    }

    public function getSubheading(): ?string
    {
        $specialty = Specialty::tryFrom((string) $this->specialty);

        return $this->brand !== null && $specialty !== null
            ? __('Pricelist').' · '.$specialty->getLabel()
            : __('Pricelist');
    }

    /**
     * @return array<string, mixed>
     */
    protected function flatFilters(): array
    {
        return [...parent::flatFilters(), 'specialty' => $this->specialty, 'brand' => $this->brand];
    }

    /**
     * The category's menu entry stays lit while one of its price lists is open.
     */
    public static function inCategory(Specialty $specialty): bool
    {
        return request()->routeIs(static::getRouteName()) && request()->query('specialty') === $specialty->value;
    }
}

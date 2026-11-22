<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Enums\ImportType;
use App\Enums\Specialty;
use App\Filament\Admin\Resources\DataImports\DataImportResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Imports\Exporter;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->label(__('Import from Excel'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->url(DataImportResource::getUrl('create', ['type' => ImportType::Products->value]))
                    ->visible(fn () => Auth::user()->can('products.import')),
                Action::make('export')
                    ->label(__('Export to Excel'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn () => app(BranchScopeResolver::class)->hasAny(Auth::user(), 'products.export'))
                    ->action(fn () => Excel::download(app(Exporter::class)->sheet(ImportType::Products, Auth::user()), app(Exporter::class)->filename(ImportType::Products))),
            ])
                ->label(__('Excel'))
                ->icon(Heroicon::OutlinedTableCells)
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }

    /**
     * One tab per specialty that has items (client, 2026-11-22: "when I click Enovis Lima only
     * Lima items, Trauma only trauma items"). The supplier catalog import files Lima / Enovis
     * items under Arthroplasty and Mikron items under Trauma, so each tab is one workbook; the
     * label names the brand most of its items carry.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $groups = $this->specialtyGroups();

        $tabs = [
            'all' => Tab::make(__('All'))
                ->badge(fn () => Display::number(ProductResource::getEloquentQuery()->count())),
        ];

        foreach ($groups as $value => $group) {
            $specialty = Specialty::from($value);
            $label = $group['brand'] !== null ? $specialty->getLabel().' · '.$group['brand'] : $specialty->getLabel();

            $tabs[$value] = Tab::make($label)
                ->icon($specialty->icon())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('category_id', $group['categories']))
                ->badge(Display::number($group['count']));
        }

        return $tabs;
    }

    /**
     * Specialty → its categories (with sub-categories), item count and main brand; only
     * specialties that have items.
     *
     * @return array<string, array{categories: list<int>, count: int, brand: ?string}>
     */
    private function specialtyGroups(): array
    {
        $bySpecialty = [];

        foreach (Category::specialtyMap() as $categoryId => $specialty) {
            if ($specialty !== null) {
                $bySpecialty[$specialty->value][] = $categoryId;
            }
        }

        $groups = [];

        foreach (Specialty::cases() as $specialty) {
            $categories = $bySpecialty[$specialty->value] ?? [];

            if ($categories === []) {
                continue;
            }

            $count = ProductResource::getEloquentQuery()->whereIn('category_id', $categories)->count();

            if ($count === 0) {
                continue;
            }

            $brandId = Product::query()->whereIn('category_id', $categories)->whereNotNull('brand_id')
                ->groupBy('brand_id')->orderByRaw('count(*) desc')->orderBy('brand_id')->value('brand_id');

            $groups[$specialty->value] = [
                'categories' => $categories,
                'count' => $count,
                'brand' => $brandId !== null ? Brand::query()->whereKey($brandId)->value('name') : null,
            ];
        }

        return $groups;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\BarcodeSymbology;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\TrackingType;
use App\Models\Branch;
use App\Models\BranchLocation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Expiry\ExpiryTransitionNotifier;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use App\Support\Gs1\Gtin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Demo catalog and stock (local/staging only): orthopedic implants (serial-tracked, GS1
 * GTINs) and consumables (lot-tracked) in DHK / EBL / BGW, spread over every expiry level,
 * with a few products below their minimum. All names are fictional.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(StockMovementService $stock, ProductService $products): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        if (Product::query()->exists()) {
            return;
        }

        $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->firstOrFail();
        Auth::setUser($admin);

        $categories = $this->categories();
        $brands = collect(['NovaJoint' => 'Germany', 'BoneTech' => 'Switzerland', 'Kurdmed' => 'Iraq'])
            ->map(fn ($country, $name) => Brand::query()->create(['name' => $name, 'country' => $country]));
        $suppliers = $this->suppliers();
        $pc = Uom::query()->where('code', 'pc')->value('id');
        $box = Uom::query()->where('code', 'box')->value('id');

        $catalog = [
            // sku, en, ar, ckb, category, brand, size, tracking, price, min, uom
            ['KN-TIB-3', 'Tibial tray', 'صينية الظنبوب', 'سینی قاپی ئەژنۆ', 'KNEE', 'NovaJoint', 'Size 3', 'serial', 780, 2, $pc],
            ['KN-TIB-4', 'Tibial tray', 'صينية الظنبوب', 'سینی قاپی ئەژنۆ', 'KNEE', 'NovaJoint', 'Size 4', 'serial', 780, 2, $pc],
            ['KN-TIB-5', 'Tibial tray', 'صينية الظنبوب', 'سینی قاپی ئەژنۆ', 'KNEE', 'NovaJoint', 'Size 5', 'serial', 780, 2, $pc],
            ['KN-FEM-4', 'Femoral component', 'المكون الفخذي', 'پارچەی ڕان', 'KNEE', 'NovaJoint', 'Size 4', 'serial', 1150, 2, $pc],
            ['KN-INS-10', 'Tibial insert', 'حشوة الظنبوب', 'پڕکەرەوەی ئەژنۆ', 'KNEE', 'NovaJoint', '10 mm', 'serial', 320, 3, $pc],
            ['HP-STM-12', 'Hip stem', 'جذع الورك', 'قەدی سمت', 'HIP', 'BoneTech', 'Size 12', 'serial', 950, 1, $pc],
            ['HP-CUP-52', 'Acetabular cup', 'كأس الحُق', 'کوپی سمت', 'HIP', 'BoneTech', '52 mm', 'serial', 870, 1, $pc],
            ['HP-HEAD-28', 'Femoral head', 'رأس الفخذ', 'سەری ڕان', 'HIP', 'BoneTech', '28 mm', 'serial', 410, 2, $pc],
            ['SP-PED-6545', 'Pedicle screw', 'برغي سويقي', 'بڕغی بڕبڕە', 'SPINE', 'Kurdmed', '6.5 × 45 mm', 'lot', 95, 20, $pc],
            ['SC-COR-3530', 'Cortical screw', 'برغي قشري', 'بڕغی توێکڵ', 'SCREW', 'Kurdmed', '3.5 × 30 mm', 'lot', 12, 50, $pc],
            ['SC-CAN-7050', 'Cannulated screw', 'برغي مجوف', 'بڕغی بۆش', 'SCREW', 'BoneTech', '7.0 × 50 mm', 'lot', 38, 20, $pc],
            ['PL-LCP-8H', 'Locking plate', 'صفيحة قفل', 'پلێتی قوفڵدار', 'PLATE', 'BoneTech', '8 holes', 'serial', 260, 2, $pc],
            ['CM-40G', 'Bone cement', 'إسمنت العظام', 'چیمەنتۆی ئێسک', 'CEMENT', 'NovaJoint', '40 g', 'lot', 55, 10, $box],
            ['KW-16', 'Kirschner wire', 'سلك كيرشنر', 'وایەری کیرشنەر', 'SCREW', 'Kurdmed', '1.6 mm', 'lot', 3, 30, $pc],
        ];

        $made = [];

        foreach ($catalog as $i => [$sku, $en, $ar, $ckb, $category, $brand, $size, $tracking, $price, $min, $uom]) {
            $made[$sku] = $products->create([
                'sku' => $sku,
                'gtin' => $tracking === 'serial' ? $this->gtin(8712345, $i + 1) : null,
                'name' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                'category_id' => $categories[$category],
                'brand_id' => $brands[$brand]->id,
                'uom_id' => $uom,
                'size' => $size,
                'tracking_type' => $tracking === 'serial' ? TrackingType::Serial : TrackingType::Lot,
                'is_implant' => $tracking === 'serial',
                'requires_expiry' => $sku !== 'KW-16',
                'purchase_price' => $price,
                'purchase_currency' => $brand === 'NovaJoint' ? 'EUR' : 'USD',
                'selling_price' => round($price * 1.4),
                'selling_currency' => 'USD',
                'min_stock_default' => $min,
                'is_active' => true,
            ]);
        }

        $products->addBarcode($made['SC-COR-3530'], 'KM-3530', BarcodeSymbology::Supplier);

        $branches = Branch::query()->whereIn('code', ['DHK', 'EBL', 'BGW'])->get()->keyBy('code');
        $shelves = $this->shelves($branches);

        // Days until expiry → covers every default level (expired, critical, warning, attention, good).
        $spread = [-12, 25, 140, 300, 720, 900];

        $plan = [
            'DHK' => ['KN-TIB-3' => 3, 'KN-TIB-4' => 4, 'KN-TIB-5' => 3, 'KN-FEM-4' => 3, 'KN-INS-10' => 5, 'HP-STM-12' => 2, 'HP-CUP-52' => 2, 'HP-HEAD-28' => 3, 'PL-LCP-8H' => 3],
            'EBL' => ['KN-TIB-4' => 2, 'KN-TIB-5' => 1, 'KN-FEM-4' => 1, 'HP-STM-12' => 2, 'HP-HEAD-28' => 1, 'PL-LCP-8H' => 2],
            'BGW' => ['KN-TIB-5' => 2, 'HP-CUP-52' => 1, 'KN-INS-10' => 2],
        ];

        $lots = [
            'DHK' => ['SP-PED-6545' => [[40, 400], [15, 60]], 'SC-COR-3530' => [[120, 800], [30, -5]], 'SC-CAN-7050' => [[25, 500]], 'CM-40G' => [[12, 160], [6, 30]], 'KW-16' => [[80, null]]],
            'EBL' => ['SP-PED-6545' => [[10, 300]], 'SC-COR-3530' => [[35, 600]], 'CM-40G' => [[4, 200]]],
            'BGW' => ['SC-COR-3530' => [[60, 700]], 'SC-CAN-7050' => [[8, 90]]],
        ];

        $serialNo = 1000;

        foreach ($plan as $code => $items) {
            $branch = $branches[$code];
            $lines = [];

            foreach ($items as $sku => $count) {
                $product = $made[$sku];

                for ($n = 0; $n < $count; $n++) {
                    $days = $spread[($serialNo + $n) % count($spread)];
                    $lines[] = new ReceiveLine(
                        productId: $product->id,
                        serial: 'SN'.$code.'-'.(++$serialNo),
                        lot: 'L'.now()->format('y').str_pad((string) (($serialNo % 7) + 1), 3, '0', STR_PAD_LEFT),
                        expiryDate: now()->addDays($days)->toDateString(),
                        productionDate: now()->subYear()->toDateString(),
                        unitCost: (string) $product->purchase_price,
                        costCurrency: (string) $product->purchase_currency,
                        supplierId: $suppliers[$n % count($suppliers)]->id,
                        // DHK shelves: A-1 knee, A-2 hip and plates, B-1 the rest. Other branches: the first shelf.
                        locationId: $shelves[$code][match (substr($sku, 0, 2)) {
                            'KN' => 0, 'HP', 'PL' => 1, default => 2
                        }] ?? $shelves[$code][0] ?? null,
                    );
                }
            }

            foreach ($lots[$code] as $sku => $buckets) {
                $product = $made[$sku];

                foreach ($buckets as $k => [$qty, $days]) {
                    $lines[] = new ReceiveLine(
                        productId: $product->id,
                        quantity: $qty,
                        lot: 'LT'.substr($sku, 0, 2).now()->format('y').($k + 1).$code,
                        expiryDate: $days === null ? null : now()->addDays($days)->toDateString(),
                        unitCost: (string) $product->purchase_price,
                        costCurrency: (string) $product->purchase_currency,
                        supplierId: $suppliers[$k % count($suppliers)]->id,
                        locationId: $shelves[$code][2] ?? $shelves[$code][0] ?? null,
                    );
                }
            }

            $stock->receive($branch, $lines, $admin, MovementType::OpeningBalance, reason: 'Demo opening stock');
        }

        // Demo branch minimums: EBL needs more knee trays than the default.
        $products->setBranchMinimum($made['KN-TIB-5'], $branches['EBL']->id, 3, 8, 4);
        $products->setBranchMinimum($made['SC-COR-3530'], $branches['BGW']->id, 80, 200, 100);

        // One corrected unit so the serial history shows a correction.
        $unit = StockUnit::query()->where('branch_id', $branches['DHK']->id)->orderBy('id')->first();
        if ($unit !== null) {
            $stock->correctUnit($unit, ['lot' => $unit->lot.'A'], 'Lot typed wrongly at receiving (demo)', $admin);
        }

        app(ExpiryTransitionNotifier::class)->run(notify: false);
    }

    /**
     * @return array<string, int> code → id
     */
    private function categories(): array
    {
        $tree = [
            'IMPL' => [['en' => 'Implants', 'ar' => 'غرسات', 'ckb' => 'چاندراوەکان'], [
                'KNEE' => ['en' => 'Knee', 'ar' => 'الركبة', 'ckb' => 'ئەژنۆ'],
                'HIP' => ['en' => 'Hip', 'ar' => 'الورك', 'ckb' => 'سمت'],
                'SPINE' => ['en' => 'Spine', 'ar' => 'العمود الفقري', 'ckb' => 'بڕبڕەی پشت'],
            ]],
            'CONS' => [['en' => 'Consumables', 'ar' => 'مستهلكات', 'ckb' => 'بەکاربراوەکان'], [
                'SCREW' => ['en' => 'Screws & wires', 'ar' => 'براغي وأسلاك', 'ckb' => 'بڕغی و وایەر'],
                'PLATE' => ['en' => 'Plates', 'ar' => 'صفائح', 'ckb' => 'پلێتەکان'],
                'CEMENT' => ['en' => 'Bone cement', 'ar' => 'إسمنت العظام', 'ckb' => 'چیمەنتۆی ئێسک'],
            ]],
        ];

        $ids = [];
        $sort = 0;

        foreach ($tree as $code => [$name, $children]) {
            $parent = Category::query()->create(['code' => $code, 'name' => $name, 'sort' => $sort++]);
            $ids[$code] = $parent->id;

            foreach ($children as $childCode => $childName) {
                $ids[$childCode] = Category::query()->create(['code' => $childCode, 'name' => $childName, 'parent_id' => $parent->id, 'sort' => $sort++])->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<Supplier>
     */
    private function suppliers(): array
    {
        return [
            Supplier::query()->create(['code' => 'NVJ', 'name' => 'NovaJoint Medical GmbH', 'country' => 'Germany', 'contact_person' => 'Anna Weber', 'phone' => '+49 30 0000 0000', 'default_currency' => 'EUR', 'payment_terms_days' => 60]),
            Supplier::query()->create(['code' => 'BTS', 'name' => 'BoneTech Supply AG', 'country' => 'Switzerland', 'contact_person' => 'Marco Rossi', 'phone' => '+41 44 000 00 00', 'default_currency' => 'USD', 'payment_terms_days' => 45]),
            Supplier::query()->create(['code' => 'KMD', 'name' => 'Kurdmed Trading', 'country' => 'Iraq', 'contact_person' => 'Karwan Ali', 'phone' => '+964 750 000 0099', 'default_currency' => 'USD', 'payment_terms_days' => 30]),
        ];
    }

    /**
     * @param  Collection<string, Branch>  $branches
     * @return array<string, list<int>>
     */
    private function shelves($branches): array
    {
        $plan = [
            'DHK' => [['A-1', ['en' => 'Knee implants', 'ar' => 'غرسات الركبة', 'ckb' => 'چاندراوی ئەژنۆ']], ['A-2', ['en' => 'Hip implants', 'ar' => 'غرسات الورك', 'ckb' => 'چاندراوی سمت']], ['B-1', ['en' => 'Screws', 'ar' => 'براغي', 'ckb' => 'بڕغی']]],
            'EBL' => [['S-1', null], ['S-2', null]],
            'BGW' => [['R-1', null]],
        ];

        $ids = [];

        foreach ($plan as $code => $shelves) {
            foreach ($shelves as [$shelf, $name]) {
                $ids[$code][] = BranchLocation::query()->create(['branch_id' => $branches[$code]->id, 'code' => $shelf, 'name' => $name])->id;
            }
        }

        return $ids;
    }

    /**
     * A valid 14-digit GTIN from a company prefix and item number.
     */
    private function gtin(int $companyPrefix, int $item): string
    {
        $body = '0'.$companyPrefix.str_pad((string) $item, 5, '0', STR_PAD_LEFT);

        return $body.Gtin::checkDigit($body);
    }
}

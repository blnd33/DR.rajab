<?php

namespace App\Services\Catalog;

use App\Enums\Governorate;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Branches\BranchService;
use App\Services\Imports\ImportPipeline;
use App\Services\Money\FxService;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample data for a test site, from the supplier's own workbook (Mikron or Lima):
 *  1. exchange rates, when none are set yet;
 *  2. five stations in different governorates (three in the Kurdistan Region, two in Iraq);
 *  3. the workbook's products, through the normal supplier-catalog import;
 *  4. some of those products in stock in the stations (lots);
 *  5. test prices: 1, 2, 3… USD by SKU, selling price = cost + 20 % (TestPricingService).
 *
 * Only on a test site or outside production. Running it again adds nothing twice: stations and
 * products are matched by code, and stock is only added while there is none.
 */
class SampleDataService
{
    /** code => [English, Arabic, Kurdish, governorate, share of the stocked products] */
    public const STATIONS = [
        'DHK' => ['Duhok', 'دهوك', 'دهۆک', Governorate::Duhok, 35],
        'EBL' => ['Erbil', 'أربيل', 'هەولێر', Governorate::Erbil, 25],
        'SUL' => ['Sulaymaniyah', 'السليمانية', 'سلێمانی', Governorate::Sulaymaniyah, 15],
        'BGW' => ['Baghdad', 'بغداد', 'بەغدا', Governorate::Baghdad, 15],
        'BSR' => ['Basra', 'البصرة', 'بەسرە', Governorate::Basra, 10],
    ];

    public function __construct(
        private readonly BranchService $branches,
        private readonly ImportPipeline $imports,
        private readonly StockMovementService $stock,
        private readonly FxService $fx,
        private readonly TestPricingService $pricing,
    ) {}

    public static function allowed(): bool
    {
        return (bool) config('orthotrack.test_site') || ! app()->isProduction();
    }

    /**
     * @param  callable(string): void|null  $say  progress lines
     * @return array{rates: bool, stations: int, imported: int, stocked_products: int, lots: int, priced: int}
     */
    public function run(string $workbook, User $by, int $products = 60, ?callable $say = null): array
    {
        if (! self::allowed()) {
            throw new RuntimeException('Sample data is only for a test site (ORTHOTRACK_TEST_SITE=true).');
        }

        if (! is_file($workbook)) {
            throw new RuntimeException("Workbook not found: {$workbook}");
        }

        $say ??= fn (string $line) => null;
        $summary = ['rates' => false, 'stations' => 0, 'imported' => 0, 'stocked_products' => 0, 'lots' => 0, 'priced' => 0];

        // 1. Exchange rates (money pages and reports need them).
        if ($this->fx->current() === null) {
            $this->fx->setRate('USD', 'IQD', '1460', $by, 'Sample data');
            $this->fx->setRate('EUR', 'USD', '1.08', $by, 'Sample data');
            $summary['rates'] = true;
            $say('Exchange rates set: 1 USD = 1,460 IQD, 1 EUR = 1.08 USD.');
        }

        // 2. Stations.
        $stations = [];

        foreach (self::STATIONS as $code => [$en, $ar, $ckb, $governorate]) {
            $branch = Branch::query()->where('code', $code)->first();

            if ($branch === null) {
                $branch = $this->branches->create([
                    'code' => $code,
                    'name' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                    'city' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                    'governorate' => $governorate,
                    'default_currency' => 'USD',
                ]);
                $summary['stations']++;
            } elseif ($branch->governorate === null) {
                $this->branches->update($branch, ['governorate' => $governorate]);
            }

            $stations[$code] = $branch;
        }

        $say(sprintf('Stations: %d new, %d in all.', $summary['stations'], count($stations)));

        // 3. The supplier workbook, through the normal import (run here, not on the queue).
        $summary['imported'] = $this->import($workbook, $by);
        $say(sprintf('Workbook imported: %d new or changed rows.', $summary['imported']));

        // 4. Stock, only while there is none.
        if (StockUnit::query()->exists() || StockBatch::query()->exists()) {
            $say('There is already stock: none added.');
        } else {
            [$summary['stocked_products'], $summary['lots']] = $this->stock($stations, $by, $products);
            $say(sprintf('Stock added: %d products, %d lots, in %d stations.', $summary['stocked_products'], $summary['lots'], count($stations)));
        }

        // 5. Test prices.
        $summary['priced'] = $this->pricing->apply($by)['products'];
        $say(sprintf('Test prices set on %d products (1, 2, 3… USD, selling +20 %%).', $summary['priced']));

        return $summary;
    }

    /**
     * Run the supplier-catalog import right away and return the rows written.
     */
    private function import(string $workbook, User $by): int
    {
        $path = 'imports/sample/'.Str::uuid().'.'.(pathinfo($workbook, PATHINFO_EXTENSION) ?: 'xlsx');
        Storage::disk(ImportPipeline::DISK)->put($path, (string) file_get_contents($workbook));

        // The check and the write are queued jobs; here they run at once.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $import = $this->imports->startStored(ImportType::SupplierCatalog, $path, basename($workbook), $by)->fresh();

            if ($import->status === ImportStatus::Failed) {
                throw new RuntimeException('The workbook could not be imported: '.$import->failure_message);
            }

            if ($import->applicableCount() === 0) {
                return 0;
            }

            $this->imports->confirm($import, $by);

            return (int) $import->fresh()->applicableCount();
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    /**
     * Spread $count implants of the workbook over the stations (by their share), each one lot.
     * Products are picked in SKU order, the same order the test prices follow.
     *
     * @param  array<string, Branch>  $stations
     * @return array{0: int, 1: int} [products, lots]
     */
    private function stock(array $stations, User $by, int $count): array
    {
        $candidates = Product::query()->where('is_implant', true)->where('is_active', true)->orderBy('sku')->get();

        if ($candidates->isEmpty()) {
            return [0, 0];
        }

        // Every n-th product, so each section of the workbook is represented.
        $step = max(1, intdiv($candidates->count(), max(1, $count)));
        $picked = $candidates->values()->filter(fn ($p, int $i) => $i % $step === 0)->take($count)->values();
        $expiry = now(config('orthotrack.timezone'))->addYears(3)->toDateString();
        $lots = 0;
        $offset = 0;
        $shares = 0;

        foreach (self::STATIONS as $code => [, , , , $share]) {
            // Cumulative shares, so the stations together take exactly the products picked.
            $shares += $share;
            $take = (int) round($picked->count() * $shares / 100) - $offset;
            $slice = $picked->slice($offset, $take)->values();
            $offset += $take;

            if ($slice->isEmpty()) {
                continue;
            }

            // Received at its test cost (1, 2, 3… USD in SKU order), so the pricing step changes no stock.
            $first = $offset - $take;
            $lines = $slice->map(fn (Product $p, int $i) => ReceiveLine::fromArray([
                'product_id' => $p->id,
                'lot' => 'SMP-'.$code.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'quantity' => 5 + ($i * 7) % 26,
                'expiry_date' => $expiry,
                'unit_cost' => (string) ($first + $i + 1),
                'cost_currency' => 'USD',
            ]))->all();

            $this->stock->receive($stations[$code], $lines, $by, reason: 'Sample data');
            $lots += count($lines);
        }

        return [$picked->count(), $lots];
    }
}

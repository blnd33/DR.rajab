<?php

use App\Enums\Governorate;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalog\SampleDataService;
use App\Services\Imports\ImportPipeline;
use App\Services\Money\FxService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Sample data for the test site from the supplier's workbook: stations in five governorates, the
 * workbook's products, stock in the stations, and test prices (1, 2, 3… USD, selling + 20 %).
 */

beforeEach(function () {
    Storage::fake(ImportPipeline::DISK);
    $this->seed(InventoryBaseSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')], ['username' => 'rajab']);
    $this->admin->forceFill(['all_branches' => true])->save();
    config(['orthotrack.test_site' => true]);
});

/**
 * A small Mikron-style workbook (plates and nails sheets, sets summary and details) on disk.
 */
function sampleWorkbook(): string
{
    $book = new Spreadsheet;
    $book->removeSheetByIndex(0);

    $sheets = [
        'ALL PLATES' => [
            1 => ['MIKRON PLATES with their SCREWS'],
            2 => [null, 'Ref. Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
            3 => ['PLATE', null, null, 'Cranial Plates'],
            4 => ['PLATE', 'MX1051011545', '8680618946390', '0.6 MM MINI SQUARE PLATE 4 HOLES'],
            5 => ['PLATE', 'MX1011313509', '8680618946383', 'CLAVICULA DISTAL LOCKING PLATE RIGHT 9 HOLES'],
            6 => ['SCREW', null, null, 'SCREWS for SMALL FRAGMENT Plates'],
            7 => ['SCREW', 'MX3008033535', '0', 'LOCKING SELF TAPPING CANCELLOUS SCREW 3.5X35 MM'],
        ],
        'ALL NAILS' => [
            1 => ['MIKRON NAILS & their SCREWS'],
            2 => ['Ref Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
            3 => [null, null, 'TIBIA Nail'],
            4 => ['MX5024207160', '8680618928297', 'TIBIA NAIL 7.0X160 MM'],
            5 => ['MX5024207170', '8680618928303', 'TIBIA NAIL 7.0X170 MM'],
        ],
        'ALL Instrument Sets Summary' => [
            1 => ['MIKRON Instrument Sets Summary'],
            2 => ['Seq.', null, 'Ref Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
            4 => ['1', 'Cranial Plate', 'MTCS00', '0', 'CRANIAL SET'],
        ],
        'ALL Instrument Sets Details' => [
            1 => ['MIKRON Instrument Sets Details'],
            3 => ['Seq.', 'Ref. Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
            5 => ['1', 'MTCS00', null, 'CRANIAL SET'],
            6 => [null, 'MTCS01', null, 'Mini Plate Quick Coupling Handle'],
        ],
    ];

    foreach ($sheets as $title => $rows) {
        $sheet = $book->createSheet()->setTitle($title);

        foreach ($rows as $r => $values) {
            foreach ($values as $i => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit(chr(ord('A') + $i).$r, $value, 's');
                }
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'mikron').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('builds stations, imports the workbook, stocks it and sets the test prices', function () {
    $summary = app(SampleDataService::class)->run(sampleWorkbook(), $this->admin, products: 5);

    expect($summary['rates'])->toBeTrue()
        ->and(app(FxService::class)->current())->not->toBeNull()
        ->and($summary['stocked_products'])->toBe(5)
        ->and($summary['priced'])->toBe(5);

    // Five stations, each in its governorate.
    foreach (SampleDataService::STATIONS as $code => [, , , $governorate]) {
        expect(Branch::query()->where('code', $code)->value('governorate'))->toBe($governorate);
    }

    // The workbook's implants, in stock, priced 1, 2, 3… USD by SKU and sold for + 20 %.
    $priced = Product::query()->whereNotNull('purchase_price')->orderBy('sku')->get();
    expect($priced->pluck('sku')->all())->toBe(['MX1011313509', 'MX1051011545', 'MX3008033535', 'MX5024207160', 'MX5024207170'])
        ->and($priced->pluck('purchase_price')->map(fn ($p) => (float) $p)->all())->toBe([1.0, 2.0, 3.0, 4.0, 5.0])
        ->and($priced->pluck('selling_price')->map(fn ($p) => (float) $p)->all())->toBe([1.2, 2.4, 3.6, 4.8, 6.0]);

    // One lot per product, received at its test cost: no corrections needed.
    $batches = StockBatch::query()->with('product')->get();
    expect($batches)->toHaveCount(5)
        ->and($batches->every(fn (StockBatch $b) => (float) $b->unit_cost === (float) $b->product->purchase_price))->toBeTrue()
        ->and($batches->every(fn (StockBatch $b) => str_starts_with((string) $b->lot, 'SMP-') && $b->quantity > 0))->toBeTrue()
        ->and(Branch::query()->where('code', 'DHK')->first()->governorate)->toBe(Governorate::Duhok);
});

it('adds nothing twice when run again', function () {
    $file = sampleWorkbook();
    app(SampleDataService::class)->run($file, $this->admin, products: 5);
    $movements = StockMovement::query()->count();

    $again = app(SampleDataService::class)->run($file, $this->admin, products: 5);

    expect($again['stations'])->toBe(0)
        ->and($again['rates'])->toBeFalse()
        ->and($again['lots'])->toBe(0)
        ->and(StockBatch::query()->count())->toBe(5)
        ->and(StockMovement::query()->count())->toBe($movements);
});

it('is refused on a real (production) site', function () {
    config(['orthotrack.test_site' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(SampleDataService::allowed())->toBeFalse()
        ->and(fn () => app(SampleDataService::class)->run(sampleWorkbook(), $this->admin))->toThrow(RuntimeException::class);

    expect(User::query()->count())->toBe(1)->and(Branch::query()->where('code', 'BSR')->exists())->toBeFalse();
});

it('runs from the command line', function () {
    $this->artisan('orthotrack:sample-data', ['file' => sampleWorkbook(), '--products' => 3, '--force' => true])
        ->expectsOutputToContain('Stock added: 3 products')
        ->expectsOutputToContain('Sample data is ready.')
        ->assertSuccessful();

    $this->artisan('orthotrack:sample-data', ['file' => '/nowhere.xlsx', '--force' => true])
        ->expectsOutputToContain('Workbook not found')
        ->assertFailed();
});

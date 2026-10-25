<?php

use App\Enums\ImportRowAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Exports\ArraySheet;
use App\Imports\RawRowsImport;
use App\Models\Activity;
use App\Models\Category;
use App\Models\DataImport;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Imports\ImportPipeline;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Module 23: upload → validate → preview (old → new) → confirm → apply → error file.
 */

beforeEach(function () {
    Storage::fake(ImportPipeline::DISK);
    $this->seed(InventoryBaseSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->actingAs($this->admin);
    $this->pipeline = app(ImportPipeline::class);
    Category::query()->create(['code' => 'KNEE', 'name' => ['en' => 'Knee', 'ar' => 'الركبة']]);
});

/**
 * Write an .xlsx with these headers + rows and run the import up to the preview.
 *
 * @param  list<string>  $headers
 * @param  list<list<mixed>>  $rows
 */
function importFile(ImportType $type, array $headers, array $rows, ?User $user = null): DataImport
{
    $path = 'imports/test-'.uniqid().'.xlsx';
    Excel::store(new ArraySheet('Data', $headers, $rows), $path, ImportPipeline::DISK);

    // The queue is "sync" in tests: the check runs right away.
    return app(ImportPipeline::class)->startStored($type, $path, 'test.xlsx', $user ?? test()->admin)->fresh();
}

const PRODUCT_HEADERS = ['SKU', 'GTIN', 'Name EN', 'Name AR', 'Name KU', 'Category', 'Brand', 'Size', 'Unit', 'Tracking type', 'Purchase price', 'Selling price', 'Currency', 'Min stock'];

it('previews new, updated and error rows with old → new values, and changes nothing yet', function () {
    Product::factory()->create(['sku' => 'KN-1', 'name' => ['en' => 'Old name', 'ar' => 'قديم'], 'size' => 'Size 3', 'purchase_price' => '100']);

    $import = importFile(ImportType::Products, PRODUCT_HEADERS, [
        ['KN-1', '', 'Tibial tray', '', '', 'knee', '', '', '', '', 120, '', 'USD', ''],
        ['KN-2', '09501101530003', 'Femoral', 'فخذي', 'ڕان', 'Knee', 'NovaJoint', 'Size 4', 'pc', 'serial', 900, 1300, 'EUR', 2],
        ['KN-3', '123', 'Bad GTIN', '', '', '', '', '', '', 'serial', '', '', '', ''],
        ['KN-4', '', '', '', '', '', '', '', '', 'lot', '', '', '', ''],
    ]);

    expect($import->status)->toBe(ImportStatus::Previewed)
        ->and([$import->new_count, $import->updated_count, $import->error_count])->toBe([1, 1, 2])
        ->and(Product::query()->count())->toBe(1);

    $update = $import->rows()->where('row_number', 2)->sole();

    expect($update->action)->toBe(ImportRowAction::Update)
        ->and($update->diff['name_en'])->toBe(['old' => 'Old name', 'new' => 'Tibial tray'])
        ->and($update->diff['purchase_price']['new'])->toBe('120.0000')
        // Empty cells are not changes: Name AR and Size stay as they are.
        ->and($update->diff)->not->toHaveKeys(['name_ar', 'size']);

    expect($import->rows()->where('row_number', 4)->sole()->errors[0])->toContain('GTIN')
        ->and($import->rows()->where('row_number', 5)->sole()->errors[0])->toContain('Name EN');
});

it('applies the confirmed rows, never overwrites with empty cells and writes an error file', function () {
    Product::factory()->create(['sku' => 'KN-1', 'name' => ['en' => 'Old', 'ar' => 'قديم'], 'size' => 'Size 3']);

    $import = importFile(ImportType::Products, PRODUCT_HEADERS, [
        ['KN-1', '', 'New name', '', '', '', '', '', '', '', '', '', '', 5],
        ['KN-2', '', 'Femoral', 'فخذي', 'ڕان', 'KNEE', 'NovaJoint', 'Size 4', 'pc', 'serial', 900, 1300, 'EUR', 2],
        ['', '', 'No SKU', '', '', '', '', '', '', '', '', '', '', ''],
    ]);

    $this->pipeline->confirm($import, $this->admin);
    $import->refresh();
    $kn1 = Product::query()->where('sku', 'KN-1')->sole();
    $kn2 = Product::query()->where('sku', 'KN-2')->sole();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->processed_rows)->toBe(2)
        ->and($kn1->getTranslation('name', 'en'))->toBe('New name')
        ->and($kn1->getTranslation('name', 'ar'))->toBe('قديم')
        ->and($kn1->size)->toBe('Size 3')
        ->and($kn1->min_stock_default)->toBe(5)
        ->and($kn2->getTranslation('name', 'ckb'))->toBe('ڕان')
        ->and($kn2->brand->name)->toBe('NovaJoint')
        ->and($kn2->purchase_currency)->toBe('EUR')
        ->and($import->error_file_path)->not->toBeNull();

    Storage::disk(ImportPipeline::DISK)->assertExists($import->error_file_path);
    Storage::disk(ImportPipeline::DISK)->assertExists($import->original_path);

    expect(Activity::query()->where('log_name', 'imports')->pluck('event')->all())->toContain('uploaded', 'confirmed', 'completed');
});

it('imports stock with Excel dates, text dates and Arabic-Indic digits', function () {
    $implant = Product::factory()->create(['sku' => 'KN-1']);
    $screw = Product::factory()->lot()->create(['sku' => 'SC-1']);
    $excelDate = 25569 + (int) (strtotime('2029-03-31 UTC') / 86400); // Excel serial for 2029-03-31

    $import = importFile(ImportType::Stock, ['SKU', 'Serial', 'Lot', 'Expiry date', 'Quantity', 'Branch', 'Purchase price', 'Currency'], [
        ['KN-1', 'SN-1', 'L1', $excelDate, 1, 'DHK', 850, 'USD'],
        ['KN-1', 'SN-2', 'L1', '٢٠٢٩-٠٤-٣٠', '', 'dhk', '', ''],
        ['SC-1', '', 'LS', '2029/05/31', 40, 'DHK', 2.5, 'EUR'],
        ['KN-1', 'SN-3', '', now()->subDay()->format('Y-m-d'), 1, 'DHK', 1, 'USD'],
    ]);

    expect($import->error_count)->toBe(0)
        ->and($import->new_count)->toBe(4)
        ->and($import->warning_count)->toBe(1);

    $this->pipeline->confirm($import, $this->admin);

    expect(StockUnit::query()->where('serial', 'SN-1')->sole()->expiry_date->toDateString())->toBe('2029-03-31')
        ->and(StockUnit::query()->where('serial', 'SN-2')->sole()->expiry_date->toDateString())->toBe('2029-04-30')
        ->and(StockBatch::query()->where('lot', 'LS')->sole()->quantity)->toBe(40)
        ->and(StockUnit::query()->where('serial', 'SN-3')->sole()->isExpired())->toBeTrue()
        ->and(StockUnit::query()->first()->movements()->first()->type->value)->toBe('import');
});

it('cannot move, consume or recount stock through Excel', function () {
    $implant = Product::factory()->create(['sku' => 'KN-1']);
    $screw = Product::factory()->lot()->create(['sku' => 'SC-1']);
    $stock = app(StockMovementService::class);

    $stock->receive(branch('DHK'), [
        ReceiveLine::fromArray(['product_id' => $implant->id, 'serial' => 'SN-HERE', 'expiry_date' => '2030-01-01', 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $implant->id, 'serial' => 'SN-USED', 'expiry_date' => '2030-01-01', 'cost_currency' => 'USD']),
        ReceiveLine::fromArray(['product_id' => $screw->id, 'lot' => 'L1', 'quantity' => 10, 'expiry_date' => '2030-01-01', 'cost_currency' => 'USD']),
    ], $this->admin);
    $stock->overrideStatus(StockUnit::query()->where('serial', 'SN-USED')->sole(), StockStatus::UsedInSurgery, 'Used', $this->admin);
    branch('EBL');

    $headers = ['SKU', 'Serial', 'Lot', 'Expiry date', 'Quantity', 'Branch', 'Purchase price', 'Currency'];

    $rejected = importFile(ImportType::Stock, $headers, [
        ['KN-1', 'SN-HERE', '', '', 1, 'EBL', '', ''],          // other branch → no move
        ['KN-1', 'SN-USED', 'L9', '', 1, 'DHK', '', ''],        // used → rejected
        ['SC-1', '', 'L1', '2030-01-01', 15, 'DHK', '', ''],    // different qty → rejected
        ['KN-1', 'SN-NEW', '', '2030-01-01', 2, 'DHK', '', ''], // serial with qty 2 → error
        ['KN-1', 'SN-NEW', '', '2030-01-01', 1, 'DHK', '', ''], // same serial twice in a file → error
    ]);

    expect($rejected->rows()->orderBy('row_number')->pluck('action')->map->value->all())->toBe(['error', 'error', 'error', 'error', 'error']);

    $import = importFile(ImportType::Stock, $headers, [
        ['SC-1', '', 'L1', '2030-01-01', 10, 'DHK', '', ''],    // same qty → no change
        ['KN-1', 'SN-HERE', 'L5', '', 1, 'DHK', 990, 'USD'],    // update lot + cost, same branch
    ]);

    expect($import->rows()->orderBy('row_number')->pluck('action')->map->value->all())->toBe(['unchanged', 'update']);

    $this->pipeline->confirm($import, $this->admin);
    $unit = StockUnit::query()->where('serial', 'SN-HERE')->sole();

    expect($unit->branch_id)->toBe(branch('DHK')->id)
        ->and($unit->lot)->toBe('L5')
        ->and($unit->base_unit_cost)->toBe('990.0000')
        ->and(StockBatch::query()->where('lot', 'L1')->sum('quantity'))->toEqual(10);
});

it('imports minimum stock per branch', function () {
    Product::factory()->create(['sku' => 'KN-1', 'min_stock_default' => 1]);

    $import = importFile(ImportType::MinStock, ['SKU', 'Branch', 'Min stock', 'Max stock', 'Reorder quantity'], [
        ['KN-1', 'DHK', 4, 10, 5],
        ['KN-1', 'DHK', 6, '', ''],
        ['KN-9', 'DHK', 1, '', ''],
    ]);

    expect([$import->new_count, $import->error_count])->toBe([1, 2]);

    $this->pipeline->confirm($import, $this->admin);

    expect(Product::query()->where('sku', 'KN-1')->sole()->minStockFor(branch('DHK')))->toBe(4);
});

it('fails clearly on a file without the required columns', function () {
    $import = importFile(ImportType::Products, ['Name', 'Price'], [['Tray', 10]]);

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_message)->toContain('SKU');
});

it('only lets people with the import permission run it, in their branches', function () {
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')]);

    expect(fn () => importFile(ImportType::Products, PRODUCT_HEADERS, [['X']], $staff))
        ->toThrow(AuthorizationException::class);

    $staff->givePermissionTo('stock.import');
    $this->actingAs($staff->fresh());
    Product::factory()->create(['sku' => 'KN-1']);
    branch('EBL');

    $import = importFile(ImportType::Stock, ['SKU', 'Serial', 'Expiry date', 'Branch'], [
        ['KN-1', 'SN-A', '2030-01-01', 'DHK'],
        ['KN-1', 'SN-B', '2030-01-01', 'EBL'],
    ], $staff->fresh());

    expect($import->rows()->orderBy('row_number')->pluck('action')->map->value->all())->toBe(['create', 'error']);
});

it('builds a template with an instructions sheet in every language', function () {
    $path = 'templates/products.xlsx';
    Excel::store($this->pipeline->template(ImportType::Products), $path, ImportPipeline::DISK);

    $sheets = Excel::toArray(new RawRowsImport, $path, ImportPipeline::DISK);
    $instructions = collect($sheets[1])->flatten()->filter()->implode("\n");

    expect($sheets[0][0])->toBe(PRODUCT_HEADERS)
        ->and($sheets[0][1][0])->toBe('KN-TIB-5')
        ->and($instructions)->toContain('English', 'العربية', 'کوردی');
});

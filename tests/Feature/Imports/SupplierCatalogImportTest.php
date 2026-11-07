<?php

use App\Enums\ImportRowAction;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\Role;
use App\Enums\TrackingType;
use App\Enums\UsageLevel;
use App\Models\DataImport;
use App\Models\InstrumentType;
use App\Models\KitType;
use App\Models\Product;
use App\Services\Imports\ImportPipeline;
use App\Services\Imports\Lima\LimaWorkbookReader;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Supplier catalog: the doctor's Lima / Enovis workbook read as he sends it — colours by
 * each sheet's own key, set contents from the details sheets, problems flagged.
 */

beforeEach(function () {
    Storage::fake(ImportPipeline::DISK);
    $this->seed(InventoryBaseSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->actingAs($this->admin);
});

/**
 * One row: [B code, C text, C/B fill, bold, D key colour, E key meaning].
 *
 * @param  list<array{0?: ?string, 1?: ?string, 2?: ?string, 3?: bool, 4?: ?string, 5?: ?string}>  $rows
 */
function catalogSheet(Spreadsheet $book, string $title, array $rows): void
{
    $sheet = new Worksheet($book, $title);
    $book->addSheet($sheet);

    foreach ($rows as $index => $row) {
        $r = $index + 1;
        [$code, $text, $fill, $bold, $keyColour, $meaning] = array_pad($row, 6, null);
        $sheet->setCellValue("A{$r}", 'Group');
        $sheet->setCellValueExplicit("B{$r}", (string) $code, 's');
        $sheet->setCellValue("C{$r}", (string) $text);

        if ($fill !== null) {
            $sheet->getStyle("B{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }

        if ($bold) {
            $sheet->getStyle("B{$r}:C{$r}")->getFont()->setBold(true);
        }

        if ($keyColour !== null) {
            $sheet->getStyle("D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($keyColour);
            $sheet->setCellValue("E{$r}", (string) $meaning);
        }
    }
}

/**
 * @param  array<string, string>  $usageOverrides  code → fill colour
 */
function limaWorkbook(array $usageOverrides = []): string
{
    $fill = fn (string $code, string $colour) => $usageOverrides[$code] ?? $colour;
    $book = new Spreadsheet;
    $book->removeSheetByIndex(0);

    catalogSheet($book, 'HIP Implants ', [
        [null, 'Hip', null, true],
        ['Code / Referrence No.', 'Items Subgroups', null, true],
        [null, 'H-MAX S', null, false, '92D050', 'most commonly used'],
        [null, 'H-MAX C', null, false, 'C5E0B4', 'less commonly used'],
        [null, 'C2', null, false, 'FF0000', 'not used / will not be ordered / not produced by the company anymore'],
        [null, 'Femoral Stems', null, true],
        ['4250.20.080', 'H-MAX S STANDARD FEMORAL STEM #8 TAPER 12/14 TI6AI4V + HA', $fill('4250.20.080', '92D050')],
        ['4260.07.090', 'H-MAX C STANDARD FEMORAL STEM #9 TAPER 12/14 FECRNIMNMONBN', 'C5E0B4'],
        ['4510.15.010', 'C2 STANDARD FEMORAL STEM # 01 TAPER 12/14 TI6AL4V', 'FF0000'],
        [null, 'Acetabular Cups', null, true],
        ['5885.42.262', 'DELTA Ceramic Liners Biolox ® Delta I.D. 40 mm - Size LARGE', '92D050'],
        ['5885.42.262', '40 mm Coupling for Dual Mobility I.D. 40mm - Size Large (Biolox® DELTA)', '92D050'],
        ['3700502203530', 'SYMBOL® Cup 44 DUAL MOBILITY HA Uncemented', '92D050'],
        ['8420.15.010', 'Bone Screw Ti6AI4V dia. 6.5 x h. 20 mm', 'C5E0B4'],
        ['8420.15.010', 'Bone Screws Ø 6.5 mm L. 20 mm', '92D050'],
    ]);

    catalogSheet($book, 'HIP Instrument Sets Summary', [
        [null, 'H-MAX S', null, false, '92D050', 'Most Commonly used'],
        [null, 'C2', null, false, 'FBE5D6', 'Sometimes may be needed but almost never ordered'],
        [null, 'H-MAX S Femoral Stems', '000000', true],
        ['9042.04.000', 'H-Max instrument set ALL-IN ONE', '92D050'],
        ['9042.06.000', 'ANTERIOR OFFSET HANDLES INSTRUMENT SET', 'FBE5D6'],
        [null, 'Additional Instruments - Upon Request', 'FFC000'],
        ['9095.11.110', 'Femoral Head Impactor', 'FBE5D6'],
    ]);

    catalogSheet($book, 'HIP Instrument Sets Details', [
        [null, 'Hip - Femoral Stems', '000000', true],
        ['9042.04.000', 'H-Max Stems instrument set ALL-IN ONE', 'FFFF00', true],
        ['9042.04.950', 'BOX STERIL. H-MAX ALL IN ONEHPF'],
        ['9042.05.080', 'H-MAX BROACH #8AISI 630'],
        ['9042.05.080', 'H-MAX BROACH #8AISI 630'],
        [null, 'Additional Instruments - Upon Request', 'FFC000'],
        ['9095.11.906', 'Head slider for femoral heads'],
        ['9042.06.000', 'ANTERIOR OFFSET HANDLES INSTRUMENT SET', 'FFFF00', true],
        ['9095.10.711', 'TRIAL HEAD D=28 MM'],
        ['9042.04.000', 'H-Max Stems instrument set ALL-IN ONE', 'FFFF00', true],
        ['9013.50.120', 'MULTIFUNCTION EXTRACTOR'],
        ['9046.11.000', 'PLS INSTRUMENT SET', 'FF0000'],
        ['9046.11.950', 'BOX PLS', 'FF0000'],
    ]);

    // Yellow means "rarely or never" here, while it is the set name colour on the details sheets.
    catalogSheet($book, 'KNEE Instrument Sets Summary ', [
        ['9066.12.000', 'SET No. 1 - EVOLUTE FEMORALE - MULTIGEN PLUS', '92D050', false, '92D050', 'Currently used frequently'],
        ['9066.47.000', 'SET No. 8 - CCK/H. Common Set', 'FFFF00', false, 'FFFF00', 'Currently used rarely or ever'],
    ]);

    catalogSheet($book, 'Shoulder Instrument Set Summary', [
        ['9013.10.000', 'SMR - GENERAL SET INSTRUMENT SET MACH1', '92D050', false, '92D050', 'Will be ordered'],
        ['9013.68.000', 'PRIMA ANATOMIC & REVERSE TRIALS INSTRUMENT SET', 'FF0000', false, 'FF0000', 'Will not be ordered'],
    ]);

    $path = 'imports/lima-'.uniqid().'.xlsx';
    Storage::disk(ImportPipeline::DISK)->makeDirectory('imports');
    (new Xlsx($book))->save(Storage::disk(ImportPipeline::DISK)->path($path));

    return $path;
}

function importCatalog(string $path): DataImport
{
    return app(ImportPipeline::class)->startStored(ImportType::SupplierCatalog, $path, 'lima.xlsx', test()->admin)->fresh();
}

it('reads usage from each sheet\'s own colour key, the set contents and the problems', function () {
    $catalog = app(LimaWorkbookReader::class)->read(Storage::disk(ImportPipeline::DISK)->path(limaWorkbook()));
    $implants = collect($catalog['implants'])->keyBy('code');
    $sets = collect($catalog['sets'])->keyBy('code');

    expect($implants['4250.20.080'])->toMatchArray([
        'usage' => 'most_used', 'area' => 'HIP', 'section' => 'Femoral Stems', 'size' => '#8', 'brand' => 'Lima (Enovis)',
        'name' => 'H-MAX S STANDARD FEMORAL STEM #8 TAPER 12/14 Ti6Al4V + HA',
    ])
        ->and($implants['4260.07.090']['usage'])->toBe('less_used')
        ->and($implants['4510.15.010']['usage'])->toBe('not_used')
        ->and($implants['3700502203530'])->toMatchArray(['brand' => 'Dedienne Santé', 'gtin' => '3700502203530', 'section' => 'Acetabular Cups'])
        // Two spellings of one bone screw: one item, the more-used level.
        ->and($implants['8420.15.010'])->toMatchArray(['usage' => 'most_used', 'conflict' => null])
        // One code for two different items: flagged.
        ->and($implants['5885.42.262']['conflict'])->toContain('two different items');

    expect($sets['9042.04.000'])->toMatchArray(['usage' => 'most_used', 'family' => 'H-MAX S Femoral Stems'])
        ->and($sets['9042.04.000']['items'])->toBe([
            ['code' => '9042.04.950', 'name' => 'BOX STERIL. H-MAX ALL IN ONE HPF', 'qty' => 1, 'required' => true],
            ['code' => '9042.05.080', 'name' => 'H-MAX BROACH #8AISI 630', 'qty' => 2, 'required' => true],
            ['code' => '9095.11.906', 'name' => 'Head slider for femoral heads', 'qty' => 1, 'required' => false],
        ])
        ->and($sets['9042.06.000']['usage'])->toBe('rarely')
        ->and($sets['9046.11.000']['usage'])->toBe('not_used')
        ->and($sets['9066.12.000']['usage'])->toBe('most_used')
        ->and($sets['9066.47.000']['usage'])->toBe('rarely')
        ->and($sets['9013.10.000']['usage'])->toBe('most_used')
        ->and($sets['9013.68.000']['usage'])->toBe('not_used')
        ->and(collect($catalog['instruments'])->pluck('code'))->toContain('9095.11.110', '9013.50.120')->not->toContain('9042.04.000');
});

it('imports the workbook: products with usage, archived discontinued items, sets with contents', function () {
    $import = importCatalog(limaWorkbook());

    expect($import->status)->toBe(ImportStatus::Previewed)
        ->and($import->error_count)->toBe(1)
        ->and($import->rows()->where('action', ImportRowAction::Error)->sole()->raw['code'])->toBe('5885.42.262')
        ->and(Product::query()->count())->toBe(0);

    app(ImportPipeline::class)->confirm($import, $this->admin);

    expect($import->fresh()->status)->toBe(ImportStatus::Completed);

    $stem = Product::query()->with(['category.parent', 'brand'])->where('sku', '4250.20.080')->sole();

    expect($stem->usage_level)->toBe(UsageLevel::MostUsed)
        ->and($stem->is_active)->toBeTrue()
        ->and($stem->tracking_type)->toBe(TrackingType::Lot)
        ->and($stem->is_implant)->toBeTrue()
        ->and($stem->size)->toBe('#8')
        ->and($stem->brand->name)->toBe('Lima (Enovis)')
        ->and($stem->category->getTranslation('name', 'en'))->toBe('Femoral Stems')
        ->and($stem->category->parent->code)->toBe('HIP')
        ->and($stem->category->parent->getTranslation('name', 'ar'))->toBe('الورك');

    expect(Product::query()->where('sku', '4510.15.010')->sole())
        ->usage_level->toBe(UsageLevel::NotUsed)
        ->is_active->toBeFalse()
        ->and(Product::query()->where('sku', '3700502203530')->value('gtin'))->toBe('3700502203530')
        ->and(Product::query()->where('sku', '5885.42.262')->exists())->toBeFalse();

    $set = KitType::query()->with('templateItems.instrumentType')->where('code', '9042.04.000')->sole();

    expect($set->usage_level)->toBe(UsageLevel::MostUsed)
        ->and($set->getTranslation('description', 'en'))->toBe('Hip · H-MAX S Femoral Stems')
        ->and($set->templateItems->map(fn ($i) => [$i->instrumentType->code, $i->expected_qty, $i->is_required])->all())->toBe([
            ['9042.04.950', 1, true], ['9042.05.080', 2, true], ['9095.11.906', 1, false],
        ])
        ->and(KitType::query()->where('code', '9013.68.000')->value('is_active'))->toBeFalse()
        ->and(InstrumentType::query()->where('code', '9095.11.110')->exists())->toBeTrue();

    // Same file again: nothing to do.
    $again = importCatalog(limaWorkbook());

    expect([$again->new_count, $again->updated_count, $again->unchanged_count])->toBe([0, 0, $again->total_rows - 1]);
});

it('updates the usage when the doctor recolours an item, and archives it when red', function () {
    app(ImportPipeline::class)->confirm(importCatalog(limaWorkbook()), $this->admin);

    $import = importCatalog(limaWorkbook(['4250.20.080' => 'FF0000']));
    $row = $import->rows()->where('action', ImportRowAction::Update)->sole();

    expect($row->raw['code'])->toBe('4250.20.080')
        ->and($row->diff['usage_level'])->toBe(['old' => 'Most used', 'new' => 'Not used']);

    app(ImportPipeline::class)->confirm($import, $this->admin);

    expect(Product::query()->where('sku', '4250.20.080')->sole())
        ->usage_level->toBe(UsageLevel::NotUsed)
        ->is_active->toBeFalse();
});

it('fails clearly on a workbook that is not a supplier catalog', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Data')->setCellValue('A1', 'SKU');
    $path = 'imports/other.xlsx';
    Storage::disk(ImportPipeline::DISK)->makeDirectory('imports');
    (new Xlsx($book))->save(Storage::disk(ImportPipeline::DISK)->path($path));

    $import = importCatalog($path);

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_message)->toContain('No supplier catalog sheets found');
});

it('orders products most-used first', function () {
    Product::factory()->create(['sku' => 'A-RARE', 'usage_level' => UsageLevel::Rarely]);
    Product::factory()->create(['sku' => 'B-NONE', 'usage_level' => null]);
    Product::factory()->create(['sku' => 'C-MOST', 'usage_level' => UsageLevel::MostUsed]);

    expect(Product::query()->orderByUsage()->orderBy('sku')->pluck('sku')->all())->toBe(['C-MOST', 'A-RARE', 'B-NONE']);
});

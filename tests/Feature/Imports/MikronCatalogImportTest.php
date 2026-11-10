<?php

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
use App\Services\Imports\SupplierWorkbookReader;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Supplier catalog: the doctor's Mikron trauma workbook read as he sends it — item sheets with
 * Ref code / GTIN / Description, set usage from the summary's colour key, set contents from the
 * details sheet.
 */

beforeEach(function () {
    Storage::fake(ImportPipeline::DISK);
    $this->seed(InventoryBaseSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->actingAs($this->admin);
});

/**
 * @param  array<int, list<?string>>  $rows  row number → values from column A
 * @param  array<int, string>  $fills  row number → fill colour of the whole row (A–E)
 */
function mikronSheet(Spreadsheet $book, string $title, array $rows, array $fills = []): Worksheet
{
    $sheet = new Worksheet($book, $title);
    $book->addSheet($sheet);

    foreach ($rows as $r => $values) {
        foreach ($values as $i => $value) {
            if ($value !== null) {
                $sheet->setCellValueExplicit(chr(ord('A') + $i).$r, $value, 's');
            }
        }
    }

    foreach ($fills as $r => $colour) {
        $sheet->getStyle("A{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
    }

    return $sheet;
}

/**
 * @param  array<string, string>  $setColours  set code → summary fill colour
 */
function mikronWorkbook(array $setColours = []): string
{
    $book = new Spreadsheet;
    $book->removeSheetByIndex(0);

    mikronSheet($book, 'ALL PLATES', [
        1 => ['MIKRON PLATES with their SCREWS in Baghdad'],
        2 => [null, 'Ref. Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
        3 => ['PLATE', null, null, 'PLATES'],
        4 => ['PLATE', null, null, 'Cranial Plates'],
        5 => ['PLATE', 'MX1051011545', '8680618946390', '0.6 MM MINI SQUARE PLATE 4 HOLES'],
        6 => ['PLATE', 'MX1011313509', '8680618946383', 'CLAVICULA DISTAL LOCKING PLATE RIGHT 9 HOLES'],
        7 => ['SCREW', null, null, 'SCREWS'],
        8 => ['SCREW', null, null, 'SCREWS for SMALL FRAGMENT Plates'],
        9 => ['SCREW', 'MX3001002506-40', 'Same as for MINI Plates', 'LOCKING SELF TAPPING CORTICAL SCREW 2.5X6-40 MM'],
        10 => ['SCREW', 'MX3008033535', '0', 'LOCKING SELF TAPPING CANCELLOUS SCREW 3.5X35 MM'],
    ], [3 => '000000', 4 => 'DFEBF7', 7 => '000000', 8 => 'DFEBF7', 9 => 'FF5050']);

    mikronSheet($book, 'ALL NAILS ', [
        1 => ['MIKRON NAILS & their SCREWS in Baghdad'],
        2 => ['Ref Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
        3 => [null, null, 'ARTHRODRSIS Nail'],
        4 => ['MX5024207160', '8680618928297', 'ARTHRODRSIS NAIL 7.0X160 MM'],
    ], [3 => '000000']);

    $red = 'FF0000';
    $green = '92D050';
    $summary = mikronSheet($book, 'ALL Instrument Sets Summary', [
        1 => ['MIKRON Instrument Sets Summary'],
        2 => ['Seq.', null, 'Ref Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
        4 => ['1', 'Cranial Plate', 'MTCS00', '0', 'CRANIAL SET'],
        5 => ['2', 'Nail', 'MTFN00', '8680618996982', 'MIKRON FEMORAL INTRAMEDULLARY NAIL INSTRUMENT SET'],
        6 => ['3', 'miscellaneous', 'MTFN45', '0', 'Instrument Case'],
    ]);

    foreach ([4 => $setColours['MTCS00'] ?? $red, 5 => $setColours['MTFN00'] ?? $green, 6 => 'FBE5D6'] as $r => $colour) {
        $summary->getStyle("C{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
    }

    foreach ([4 => [$red, 'Not ordered / Not available in the company / Factory don\'t produce it'], 5 => ['FBE5D6', 'Rarely used or rarely needed'], 6 => [$green, 'Most Commonly used']] as $r => [$colour, $meaning]) {
        $summary->getStyle("F{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
        $summary->setCellValue("G{$r}", $meaning);
    }

    mikronSheet($book, 'ALL Instrument Sets Details', [
        1 => ['MIKRON Instrument Sets Details'],
        3 => ['Seq.', 'Ref. Code', 'EAN CODE (GTIN) BARCODE', 'Description'],
        5 => ['1', 'MTCS00', null, 'CRANIAL SET'],
        6 => [null, 'MTCS01', null, 'Mini Plate Quick Coupling Handle'],
        7 => [null, 'MTCS02', null, 'Screw Forcep'],
        9 => ['2', 'MTFN00', '8680618996982', 'MIKRON FEMORAL INTRAMEDULLARY NAIL INSTRUMENT SET'],
        10 => [null, 'MTFN25-10.0', '8684605142239', 'Reamers (Ø10.0)'],
        11 => [null, 'MTFN25-10.5', '8684605142246', 'Reamers (Ø10.5)'],
        12 => [null, 'MTFN26', '8684605142307', 'Locking Block'],
        13 => [null, 'MTFN26', '8684605142307', 'Locking Block'],
        14 => [null, 'MTFN45', null, 'Instrument Case **'],
        16 => [null, null, null, 'Miscellaneous Instrument Sets'],
        17 => ['3', 'MTFN45', null, 'Instrument Case'],
    ]);

    $path = 'imports/mikron-'.uniqid().'.xlsx';
    Storage::disk(ImportPipeline::DISK)->makeDirectory('imports');
    (new Xlsx($book))->save(Storage::disk(ImportPipeline::DISK)->path($path));

    return $path;
}

function importMikron(string $path): DataImport
{
    return app(ImportPipeline::class)->startStored(ImportType::SupplierCatalog, $path, 'mikron.xlsx', test()->admin)->fresh();
}

it('reads the Mikron workbook: groups, barcodes, sizes, set usage and contents', function () {
    $catalog = app(SupplierWorkbookReader::class)->read(Storage::disk(ImportPipeline::DISK)->path(mikronWorkbook()));
    $implants = collect($catalog['implants'])->keyBy('code');
    $sets = collect($catalog['sets'])->keyBy('code');

    // The code range ("Same as for MINI Plates") points at items listed elsewhere.
    expect($implants->keys()->all())->toBe(['MX1051011545', 'MX1011313509', 'MX3008033535', 'MX5024207160']);

    expect($implants['MX1011313509'])->toMatchArray([
        'area' => 'TRAUMA', 'section' => 'Cranial Plates', 'brand' => 'Mikron', 'gtin' => '8680618946383',
        'size' => '9 holes · Right', 'usage' => null,
    ])
        ->and($implants['MX3008033535'])->toMatchArray(['section' => 'SCREWS for SMALL FRAGMENT Plates', 'gtin' => null, 'size' => '3.5 × 35 mm'])
        ->and($implants['MX5024207160'])->toMatchArray(['section' => 'ARTHRODESIS Nail', 'name' => 'ARTHRODESIS NAIL 7.0X160 MM']);

    expect($sets->keys()->all())->toBe(['MTCS00', 'MTFN00'])
        ->and($sets['MTCS00'])->toMatchArray(['usage' => 'not_used', 'family' => 'Cranial Plate'])
        ->and($sets['MTFN00'])->toMatchArray(['usage' => 'most_used', 'family' => 'Nail'])
        ->and($sets['MTFN00']['items'])->toBe([
            ['code' => 'MTFN25-10.0', 'name' => 'Reamers (Ø10.0)', 'qty' => 1, 'required' => true],
            ['code' => 'MTFN25-10.5', 'name' => 'Reamers (Ø10.5)', 'qty' => 1, 'required' => true],
            ['code' => 'MTFN26', 'name' => 'Locking Block', 'qty' => 2, 'required' => true],
            ['code' => 'MTFN45', 'name' => 'Instrument Case', 'qty' => 1, 'required' => true],
        ]);

    // A "miscellaneous set" that is only a case inside another set stays an instrument.
    expect(collect($catalog['instruments'])->pluck('code')->all())->toBe(['MTCS01', 'MTCS02', 'MTFN25-10.0', 'MTFN25-10.5', 'MTFN26', 'MTFN45']);
});

it('imports the Mikron workbook under Trauma, with sets and their instruments', function () {
    $import = importMikron(mikronWorkbook());

    expect($import->status)->toBe(ImportStatus::Previewed)
        ->and($import->error_count)->toBe(0)
        ->and($import->new_count)->toBe(12);

    app(ImportPipeline::class)->confirm($import, $this->admin);

    $plate = Product::query()->with(['category.parent', 'brand'])->where('sku', 'MX1011313509')->sole();

    expect($plate)
        ->gtin->toBe('8680618946383')
        ->size->toBe('9 holes · Right')
        ->tracking_type->toBe(TrackingType::Lot)
        ->is_implant->toBeTrue()
        ->is_active->toBeTrue()
        ->and($plate->brand->name)->toBe('Mikron')
        ->and($plate->category->getTranslation('name', 'en'))->toBe('Cranial Plates')
        ->and($plate->category->parent->code)->toBe('TRAUMA')
        ->and($plate->category->parent->getTranslation('name', 'ar'))->toBe('الكسور');

    $set = KitType::query()->with('templateItems.instrumentType')->where('code', 'MTFN00')->sole();

    expect($set->usage_level)->toBe(UsageLevel::MostUsed)
        ->and($set->getTranslation('description', 'en'))->toBe('Trauma · Nail')
        ->and($set->templateItems->map(fn ($i) => [$i->instrumentType->code, $i->expected_qty])->all())
        ->toBe([['MTFN25-10.0', 1], ['MTFN25-10.5', 1], ['MTFN26', 2], ['MTFN45', 1]])
        ->and(KitType::query()->where('code', 'MTCS00')->sole())->usage_level->toBe(UsageLevel::NotUsed)->is_active->toBeTrue()
        ->and(InstrumentType::query()->where('code', 'MTFN45')->exists())->toBeTrue()
        ->and(KitType::query()->where('code', 'MTFN45')->exists())->toBeFalse();

    // Same file again: nothing to do; a recoloured set updates its usage.
    $again = importMikron(mikronWorkbook());
    expect([$again->new_count, $again->updated_count])->toBe([0, 0]);

    $recoloured = importMikron(mikronWorkbook(['MTCS00' => '92D050']));
    expect($recoloured->updated_count)->toBe(1);
});

it('still tells which workbooks it can read', function () {
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Stock')->setCellValue('A1', 'SKU');
    $path = 'imports/other.xlsx';
    Storage::disk(ImportPipeline::DISK)->makeDirectory('imports');
    (new Xlsx($book))->save(Storage::disk(ImportPipeline::DISK)->path($path));

    expect(importMikron($path))
        ->status->toBe(ImportStatus::Failed)
        ->failure_message->toContain('Mikron');
});

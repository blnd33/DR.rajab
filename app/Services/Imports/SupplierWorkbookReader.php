<?php

namespace App\Services\Imports;

use App\Services\Imports\Lima\LimaWorkbookReader;
use App\Services\Imports\Mikron\MikronWorkbookReader;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Picks the reader for a supplier workbook by its sheet names: the Lima / Enovis file
 * (Hip / Knee / Shoulder sheets) or the Mikron trauma file (Plates, Nails, Instrument Sets).
 * Both give the same records (implants, instruments, sets).
 */
class SupplierWorkbookReader
{
    public function __construct(
        private readonly LimaWorkbookReader $lima,
        private readonly MikronWorkbookReader $mikron,
    ) {}

    /**
     * @return array{implants: list<array<string, mixed>>, instruments: list<array<string, mixed>>, sets: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        try {
            // Sheet names only (fast); a format without that shortcut is opened in full.
            $reader = IOFactory::createReaderForFile($path);
            $titles = method_exists($reader, 'listWorksheetNames')
                ? array_values($reader->listWorksheetNames($path))
                : $reader->load($path)->getSheetNames();
        } catch (\Throwable) {
            throw new ImportFileInvalid(__('The file could not be read. Save it as .xlsx and try again.'));
        }

        return match (true) {
            LimaWorkbookReader::recognises($titles) => $this->lima->read($path),
            MikronWorkbookReader::recognises($titles) => $this->mikron->read($path),
            default => throw new ImportFileInvalid(__('No supplier catalog sheets found. Upload the Lima / Enovis workbook (Hip, Knee, Shoulder sheets) or the Mikron workbook (Plates, Nails, Instrument Sets sheets) as it is.')),
        };
    }
}

<?php

namespace App\Services\Imports\Mikron;

use App\Enums\UsageLevel;
use App\Services\Imports\ImportFileInvalid;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads a trauma workbook laid out like the doctor's Mikron file, as he sends it:
 *   - item sheets (plates, cannulated / Herbert / headless screws, nails): Ref code, EAN code
 *     (GTIN) and Description, with heading rows ("Cranial Plates", "SCREWS for PELVIC Plates")
 *     that give the group;
 *   - "Instrument Sets Summary": one row per set with its group, coloured by usage, and a
 *     colour key beside it;
 *   - "Instrument Sets Details": each set (numbered in Seq.) followed by its instruments.
 *
 * Columns are found by their header text, not by letter (the sheets differ). Code ranges such
 * as "MX3001002506-40" ("Same as for MINI Plates") point at items listed elsewhere: skipped.
 * Returns the same records as the Lima reader, so the import checks them the same way.
 */
class MikronWorkbookReader
{
    public const AREA = 'TRAUMA';

    public const BRAND = 'Mikron';

    /** Spelling slips in the source file → correct spelling. */
    private const TYPOS = [
        '/\bARTHRODRSIS\b/i' => 'ARTHRODESIS',
        '/\bSamll\b/i' => 'Small',
    ];

    /** @var array<string, array<string, mixed>> code → implant */
    private array $implants = [];

    /** @var array<string, array<string, mixed>> code → instrument */
    private array $instruments = [];

    /** @var array<string, array<string, mixed>> code → set */
    private array $sets = [];

    /**
     * Does a workbook with these sheet names look like the Mikron file?
     *
     * @param  list<string>  $titles
     */
    public static function recognises(array $titles): bool
    {
        foreach ($titles as $title) {
            if (preg_match('/\binstrument\s+sets?\b|\b(plates|nails)\b|\bcann/i', $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{implants: list<array<string, mixed>>, instruments: list<array<string, mixed>>, sets: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        $this->implants = $this->instruments = $this->sets = [];

        try {
            $book = IOFactory::load($path);
        } catch (\Throwable) {
            throw new ImportFileInvalid(__('The file could not be read. Save it as .xlsx and try again.'));
        }

        $sheets = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            $columns = $this->columns($sheet);

            if ($columns === null) {
                continue;
            }

            $kind = match (true) {
                (bool) preg_match('/\bdetail/i', $sheet->getTitle()) => 'details',
                (bool) preg_match('/\bsummary\b/i', $sheet->getTitle()) => 'summary',
                default => 'implants',
            };
            $sheets[$kind][] = [$sheet, $columns];
        }

        if ($sheets === []) {
            throw new ImportFileInvalid(__('No supplier catalog sheets found. Each sheet needs a header row with Ref Code and Description.'));
        }

        // The summary first: it holds the set groups and usage the details sheet refers to.
        foreach ($sheets['implants'] ?? [] as [$sheet, $columns]) {
            $this->readImplants($sheet, $columns);
        }

        foreach ($sheets['summary'] ?? [] as [$sheet, $columns]) {
            $this->readSummary($sheet, $columns);
        }

        foreach ($sheets['details'] ?? [] as [$sheet, $columns]) {
            $this->readDetails($sheet, $columns);
        }

        $book->disconnectWorksheets();
        unset($book, $sheets);

        $members = [];

        foreach ($this->sets as $set) {
            foreach ($set['items'] as $item) {
                $members[$item['code']] = true;
            }
        }

        foreach ($this->sets as $code => $set) {
            // "Miscellaneous" entries (a tray, a plate bender) have no contents of their own and
            // are part of another set: they are instruments, not sets.
            if ($set['items'] === [] && isset($members[$code])) {
                unset($this->sets[$code]);

                continue;
            }

            unset($this->instruments[$code]);
            $this->sets[$code]['items'] = array_values($set['items']);
        }

        return [
            'implants' => array_values($this->implants),
            'instruments' => array_values($this->instruments),
            'sets' => array_values($this->sets),
        ];
    }

    /**
     * @param  array{row: int, code: string, gtin: ?string, name: string, seq: ?string, group: ?string}  $columns
     */
    private function readImplants(Worksheet $sheet, array $columns): void
    {
        $section = null;

        for ($r = $columns['row'] + 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $raw = $this->cell($sheet, $columns['code'], $r);
            $code = $this->code($raw);
            $text = $this->clean($this->cell($sheet, $columns['name'], $r));

            if ($code === null) {
                // A heading row has a description but no code.
                if ($text !== '' && trim($raw) === '') {
                    $section = $text;
                }

                continue;
            }

            $gtin = $this->gtin($sheet, $columns['gtin'], $r);

            // A code range without a barcode ("MX3001002506-40", "Same as for MINI Plates")
            // points at items listed elsewhere.
            if ($text === '' || (str_contains($code, '-') && $gtin === null)) {
                continue;
            }

            if (isset($this->implants[$code])) {
                if ($this->similarity($this->implants[$code]['name'], $text) < 0.5) {
                    $this->implants[$code]['conflict'] = __('Code :code is used for two different items: ":first" (:sheet row :row) and ":second" (row :row2). Ask which one is right.', [
                        'code' => $code,
                        'first' => $this->implants[$code]['name'],
                        'sheet' => $this->implants[$code]['sheet'],
                        'row' => $this->implants[$code]['row'],
                        'second' => $text,
                        'row2' => $r,
                    ]);
                }

                continue;
            }

            $this->implants[$code] = [
                'kind' => 'implant',
                'code' => $code,
                'name' => $text,
                'area' => self::AREA,
                'section' => $section,
                'usage' => null,
                'size' => $this->size($text),
                'brand' => self::BRAND,
                'gtin' => $gtin,
                'sheet' => trim($sheet->getTitle()),
                'row' => $r,
                'conflict' => null,
            ];
        }
    }

    /**
     * @param  array{row: int, code: string, gtin: ?string, name: string, seq: ?string, group: ?string}  $columns
     */
    private function readSummary(Worksheet $sheet, array $columns): void
    {
        $key = $this->colourKey($sheet, $columns['name']);

        for ($r = $columns['row'] + 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->code($this->cell($sheet, $columns['code'], $r));
            $text = $this->clean($this->cell($sheet, $columns['name'], $r));

            if ($code === null || $text === '') {
                continue;
            }

            $usage = $key[$this->fillOf($sheet, $columns['code'], $r) ?? $this->fillOf($sheet, $columns['name'], $r) ?? ''] ?? null;
            $group = $columns['group'] !== null ? $this->clean($this->cell($sheet, $columns['group'], $r)) : '';

            $this->sets[$code] ??= $this->set($code, $text, $sheet, $r);
            $this->sets[$code]['family'] = $group === '' ? null : ucfirst($group);
            $this->sets[$code]['usage'] = $usage?->value;
        }
    }

    /**
     * @param  array{row: int, code: string, gtin: ?string, name: string, seq: ?string, group: ?string}  $columns
     */
    private function readDetails(Worksheet $sheet, array $columns): void
    {
        $current = null;

        for ($r = $columns['row'] + 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->code($this->cell($sheet, $columns['code'], $r));
            $text = $this->clean($this->cell($sheet, $columns['name'], $r));

            if ($code === null) {
                if ($text !== '') {
                    $current = null;
                }

                continue;
            }

            if ($text === '') {
                continue;
            }

            // A set starts with its number in Seq.; the instruments under it have none.
            $seq = $columns['seq'] !== null ? trim($this->cell($sheet, $columns['seq'], $r)) : '';

            if (ctype_digit($seq)) {
                $this->sets[$code] ??= $this->set($code, $text, $sheet, $r);
                $current = $code;

                continue;
            }

            $this->instruments[$code] ??= [
                'kind' => 'instrument',
                'code' => $code,
                'name' => $text,
                'gtin' => $this->gtin($sheet, $columns['gtin'], $r),
                'sheet' => trim($sheet->getTitle()),
                'row' => $r,
            ];

            if ($current !== null) {
                $items = &$this->sets[$current]['items'];

                if (isset($items[$code])) {
                    $items[$code]['qty']++;
                } else {
                    $items[$code] = ['code' => $code, 'name' => $this->instruments[$code]['name'], 'qty' => 1, 'required' => true];
                }

                unset($items);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function set(string $code, string $name, Worksheet $sheet, int $row): array
    {
        return [
            'kind' => 'set',
            'code' => $code,
            'name' => $name,
            'area' => self::AREA,
            'family' => null,
            'usage' => null,
            'usage_from_details' => false,
            'items' => [],
            'sheet' => trim($sheet->getTitle()),
            'row' => $row,
        ];
    }

    /**
     * The header row (within the first rows) and the column of each field, by header text.
     *
     * @return array{row: int, code: string, gtin: ?string, name: string, seq: ?string, group: ?string}|null
     */
    private function columns(Worksheet $sheet): ?array
    {
        $letters = range('A', 'H');

        for ($r = 1; $r <= min(8, $sheet->getHighestDataRow()); $r++) {
            $found = ['code' => null, 'gtin' => null, 'name' => null, 'seq' => null];

            foreach ($letters as $letter) {
                $header = mb_strtolower($this->clean($this->cell($sheet, $letter, $r)));

                $field = match (true) {
                    $header === '' => null,
                    (bool) preg_match('/\b(ean|gtin|barcode)\b/', $header) => 'gtin',
                    (bool) preg_match('/^ref\b/', $header) => 'code',
                    (bool) preg_match('/^description\b/', $header) => 'name',
                    (bool) preg_match('/^seq\b/', $header) => 'seq',
                    default => null,
                };

                if ($field !== null) {
                    $found[$field] ??= $letter;
                }
            }

            if ($found['code'] !== null && $found['name'] !== null) {
                // The set group sits in the unnamed column right before the code (summary sheet).
                $before = chr(ord($found['code']) - 1);
                $group = $found['code'] !== 'A' && $before !== $found['seq'] && trim($this->cell($sheet, $before, $r)) === '' ? $before : null;

                return ['row' => $r] + $found + ['group' => $group];
            }
        }

        return null;
    }

    /**
     * colour (RGB) → usage level, from the key beside the table (a coloured cell with its
     * meaning in the next column).
     *
     * @return array<string, UsageLevel>
     */
    private function colourKey(Worksheet $sheet, string $nameColumn): array
    {
        $key = [];

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            for ($c = ord($nameColumn) + 1; $c <= ord($nameColumn) + 3; $c++) {
                $colour = $this->fillOf($sheet, chr($c), $r);
                $level = UsageLevel::fromKeyText($this->cell($sheet, chr($c + 1), $r));

                if ($colour !== null && $level !== null) {
                    $key[$colour] ??= $level;
                }
            }
        }

        return $key;
    }

    /**
     * "2.5 × 6 mm · 4 holes · Right" — the size parts of a description.
     */
    public function size(string $text): ?string
    {
        $parts = [];

        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*[X×]\s*(\d+(?:[.,]\d+)?)\s*MM\b/iu', $text, $m)) {
            $parts[] = str_replace(',', '.', $m[1]).' × '.str_replace(',', '.', $m[2]).' mm';
        }

        if (preg_match('/\b(\d+)\s*HOLES?\b/iu', $text, $m)) {
            $parts[] = trans_choice(':count hole|:count holes', (int) $m[1], ['count' => $m[1]]);
        }

        if (preg_match('/\b(right|left)\b/iu', $text, $m)) {
            $parts[] = mb_strtolower($m[1]) === 'right' ? __('Right') : __('Left');
        }

        $size = implode(' · ', $parts);

        return $size === '' ? null : mb_substr($size, 0, 64);
    }

    public function clean(string $text): string
    {
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);

        foreach (self::TYPOS as $pattern => $fix) {
            $text = preg_replace($pattern, $fix, $text) ?? $text;
        }

        return rtrim($text, " *\t");
    }

    private function similarity(string $a, string $b): float
    {
        $words = fn (string $s) => array_unique(array_filter(preg_split('/[^A-Z]+/', mb_strtoupper($s), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn (string $w) => mb_strlen($w) >= 3));
        [$x, $y] = [$words($a), $words($b)];
        $union = count(array_unique(array_merge($x, $y)));

        return $union === 0 ? 1.0 : count(array_intersect($x, $y)) / $union;
    }

    /**
     * A code: one word of letters and digits with a digit (MX1051011544, MTSF00, MTANI00, and
     * size variants such as the reamer MTFN25-10.0).
     */
    private function code(string $value): ?string
    {
        $code = mb_strtoupper(trim($value));

        return preg_match('/^[0-9A-Z][0-9A-Z.\-]{3,31}$/', $code) && preg_match('/\d/', $code) ? $code : null;
    }

    private function gtin(Worksheet $sheet, ?string $column, int $row): ?string
    {
        if ($column === null) {
            return null;
        }

        $gtin = trim($this->cell($sheet, $column, $row));

        return preg_match('/^\d{8,14}$/', $gtin) && ltrim($gtin, '0') !== '' ? $gtin : null;
    }

    private function fillOf(Worksheet $sheet, string $column, int $row): ?string
    {
        $fill = $sheet->getStyle("{$column}{$row}")->getFill();

        if ($fill->getFillType() === null || $fill->getFillType() === 'none') {
            return null;
        }

        $rgb = mb_strtoupper($fill->getStartColor()->getRGB());

        return $rgb === 'FFFFFF' ? null : $rgb;
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return (string) $sheet->getCell("{$column}{$row}")->getFormattedValue();
    }
}

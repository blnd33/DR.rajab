<?php

namespace App\Services\Imports\Lima;

use App\Enums\UsageLevel;
use App\Services\Imports\ImportFileInvalid;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads a supplier workbook laid out like the doctor's Lima / Enovis file, as he sends it:
 * per area (Hip, Knee, Shoulder) an implants sheet, an instrument-set summary and the
 * instrument-set details. Columns: A group, B code, C description; a colour key sits in
 * D (colour) + E (meaning) of each sheet.
 *
 * Colours are read by meaning, never by value: every sheet's own key is mapped to a usage
 * level (the same colour means different things on different sheets). Set name rows are
 * recognised by their code (…000), not by colour (yellow on one sheet, amber on another).
 */
class LimaWorkbookReader
{
    /** Two descriptions of one code sharing fewer item words than this are different items. */
    private const SAME_ITEM_SIMILARITY = 0.5;

    /** Spelling slips in the source file → correct spelling. */
    private const TYPOS = [
        '/\bSsytem\b/i' => 'System',
        '/\bSytem\b/i' => 'System',
        '/\bSAPCER\b/' => 'SPACER',
        '/\bPARTICAL\b/' => 'PARTIAL',
        '/\bMODULS\b/' => 'MODULUS',
        '/\bTaber\b/' => 'Taper',
        '/\bTi6A[I1]4V\b/i' => 'Ti6Al4V',
        '/\bTi6A4V\b/i' => 'Ti6Al4V',
    ];

    /** @var array<string, array<string, mixed>> code → implant */
    private array $implants = [];

    /** @var array<string, array<string, mixed>> code → instrument */
    private array $instruments = [];

    /** @var array<string, array<string, mixed>> code → set */
    private array $sets = [];

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
            $area = $this->area($sheet->getTitle());
            $kind = $this->sheetKind($sheet->getTitle());

            if ($area !== null && $kind !== null) {
                $sheets[$kind][] = [$sheet, $area];
            }
        }

        if (($sheets['implants'] ?? []) === [] && ($sheets['summary'] ?? []) === []) {
            throw new ImportFileInvalid(__('No supplier catalog sheets found. Sheet names must say the area (Hip, Knee, Shoulder) and Implants, Summary or Details.'));
        }

        // Summaries first: they hold the set names and usage the details sheets refer to.
        foreach ($sheets['implants'] ?? [] as [$sheet, $area]) {
            $this->readImplants($sheet, $area);
        }

        foreach ($sheets['summary'] ?? [] as [$sheet, $area]) {
            $this->readSummary($sheet, $area);
        }

        foreach ($sheets['details'] ?? [] as [$sheet, $area]) {
            $this->readDetails($sheet, $area);
        }

        // The workbook holds cyclic references: free it before the rows are checked.
        $book->disconnectWorksheets();
        unset($book, $sheets);

        foreach ($this->sets as $code => $set) {
            unset($this->instruments[$code]);
            $this->sets[$code]['items'] = array_values($set['items']);
        }

        return [
            'implants' => array_values($this->implants),
            'instruments' => array_values($this->instruments),
            'sets' => array_values($this->sets),
        ];
    }

    private function readImplants(Worksheet $sheet, string $area): void
    {
        $key = $this->colourKey($sheet);
        $section = null;

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->code($sheet, $r);
            $text = $this->clean($this->cell($sheet, 'C', $r));

            if ($code === null) {
                if ($text !== '' && $this->isHeading($sheet, $r) && ! preg_match('/^(\*|note\b)/i', $text)) {
                    $section = rtrim($text, " *\t");
                }

                continue;
            }

            if ($text === '') {
                continue;
            }

            $usage = $key[$this->fill($sheet, $r)] ?? null;

            if (isset($this->implants[$code])) {
                $this->mergeDuplicate($code, $text, $usage, $sheet, $r);

                continue;
            }

            $this->implants[$code] = [
                'kind' => 'implant',
                'code' => $code,
                'name' => $text,
                'area' => $area,
                'section' => $section,
                'usage' => $usage?->value,
                'size' => $this->size($text),
                'brand' => preg_match('/\bSYMBOL\b/i', $text) ? 'Dedienne Santé' : 'Lima (Enovis)',
                'gtin' => preg_match('/^\d{13}$/', $code) ? $code : null,
                'sheet' => $sheet->getTitle(),
                'row' => $r,
                'conflict' => null,
            ];
        }
    }

    private function readSummary(Worksheet $sheet, string $area): void
    {
        $key = $this->colourKey($sheet);
        $family = null;

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->code($sheet, $r);
            $text = $this->clean($this->cell($sheet, 'C', $r));

            if ($code === null) {
                // A family heading (in B or C) sits right above its sets, sometimes with a black
                // separator row between; the list of families at the top of the sheet does not.
                $heading = $this->clean($this->cell($sheet, 'C', $r)) ?: $this->clean($this->cell($sheet, 'B', $r));
                $next = $this->code($sheet, $r + 1) !== null
                    || ($this->isBlank($sheet, $r + 1) && $this->fillOf($sheet, 'C', $r + 1) === '000000' && $this->code($sheet, $r + 2) !== null);

                if ($heading !== '' && $next && ! $this->isOnRequestHeading($heading)) {
                    $family = $heading;
                }

                continue;
            }

            if ($text === '') {
                continue;
            }

            $usage = $key[$this->fill($sheet, $r)] ?? null;

            if ($this->isSetCode($code)) {
                $this->addSet($code, $text, $area, $family, $usage, $sheet, $r);
            } else {
                $this->instruments[$code] ??= $this->instrument($code, $text, $sheet, $r);
            }
        }
    }

    private function readDetails(Worksheet $sheet, string $area): void
    {
        $current = null;
        $optional = false;

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $code = $this->code($sheet, $r);
            $text = $this->clean($this->cell($sheet, 'C', $r));

            if ($code === null) {
                if ($text !== '' && $this->isOnRequestHeading($text)) {
                    $optional = true;
                } elseif ($text !== '' && $this->isHeading($sheet, $r)) {
                    $current = null;
                }

                continue;
            }

            if ($text === '') {
                continue;
            }

            if ($this->isSetCode($code)) {
                // Red set blocks on the details sheets are sets of systems the doctor doesn't use.
                $red = $this->fill($sheet, $r) === 'FF0000';
                $listed = isset($this->sets[$code]) && $this->sets[$code]['items'] !== [];
                $this->addSet($code, $text, $area, null, $red ? UsageLevel::NotUsed : null, $sheet, $r, fromDetails: true);
                // A shared set (reamers, trial heads…) is written out again under each family:
                // its contents are taken from the first list.
                $current = $listed ? null : $code;
                $optional = false;

                continue;
            }

            $this->instruments[$code] ??= $this->instrument($code, $text, $sheet, $r);

            if ($current !== null) {
                $items = &$this->sets[$current]['items'];

                if (isset($items[$code])) {
                    $items[$code]['qty']++;
                } else {
                    $items[$code] = ['code' => $code, 'name' => $this->instruments[$code]['name'], 'qty' => 1, 'required' => ! $optional];
                }

                unset($items);
            }
        }
    }

    private function addSet(string $code, string $name, string $area, ?string $family, ?UsageLevel $usage, Worksheet $sheet, int $row, bool $fromDetails = false): void
    {
        if (! isset($this->sets[$code])) {
            $this->sets[$code] = [
                'kind' => 'set',
                'code' => $code,
                'name' => $name,
                'area' => $area,
                'family' => $family,
                'usage' => $usage?->value,
                'usage_from_details' => $fromDetails,
                'items' => [],
                'sheet' => $sheet->getTitle(),
                'row' => $row,
            ];

            return;
        }

        $set = &$this->sets[$code];
        $set['family'] ??= $family;

        // A set listed in several places keeps its most-used level; the summary wins over the
        // details sheet's red block.
        $known = UsageLevel::tryFrom((string) $set['usage']);

        if ($usage !== null && ($known === null || ($set['usage_from_details'] && ! $fromDetails) || (! $fromDetails && $usage->rank() < $known->rank()))) {
            $set['usage'] = $usage->value;
            $set['usage_from_details'] = $fromDetails;
        }

        unset($set);
    }

    /**
     * @return array<string, mixed>
     */
    private function instrument(string $code, string $name, Worksheet $sheet, int $row): array
    {
        return ['kind' => 'instrument', 'code' => $code, 'name' => $name, 'sheet' => $sheet->getTitle(), 'row' => $row];
    }

    private function mergeDuplicate(string $code, string $text, ?UsageLevel $usage, Worksheet $sheet, int $row): void
    {
        $implant = &$this->implants[$code];

        if ($this->similarity($implant['name'], $text) < self::SAME_ITEM_SIMILARITY) {
            $implant['conflict'] = __('Code :code is used for two different items: ":first" (:sheet row :row) and ":second" (row :row2). Ask which one is right.', [
                'code' => $code,
                'first' => $implant['name'],
                'sheet' => $implant['sheet'],
                'row' => $implant['row'],
                'second' => $text,
                'row2' => $row,
            ]);
        }

        // Listed twice for the same item: one product, the more-used level wins.
        $known = UsageLevel::tryFrom((string) $implant['usage']);

        if ($usage !== null && ($known === null || $usage->rank() < $known->rank())) {
            $implant['usage'] = $usage->value;
        }

        unset($implant);
    }

    /**
     * colour (RGB) → usage level, from this sheet's key (coloured D cell + meaning in E).
     *
     * @return array<string, UsageLevel>
     */
    private function colourKey(Worksheet $sheet): array
    {
        $key = [];

        for ($r = 1, $last = $sheet->getHighestDataRow(); $r <= $last; $r++) {
            $meaning = $this->clean($this->cell($sheet, 'E', $r));
            $colour = $this->fillOf($sheet, 'D', $r);

            if ($meaning === '' || $colour === null) {
                continue;
            }

            $level = UsageLevel::fromKeyText($meaning);

            if ($level !== null) {
                $key[$colour] ??= $level;
            }
        }

        return $key;
    }

    /**
     * "#8 · Ø 28 mm · H 10 mm · Right" — the size parts of a description.
     */
    public function size(string $text): ?string
    {
        $parts = [];

        if (preg_match('/#\s*([0-9]+[A-Z]?)\b/u', $text, $m)) {
            $parts[] = '#'.$m[1];
        }

        if (preg_match('/\b(?:dia\.?|Ø|D\.)\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:mm\b)?/iu', $text, $m)) {
            $parts[] = 'Ø '.str_replace(',', '.', $m[1]).' mm';
        }

        if (preg_match('/\b(?:H|h|L)\.?\s*=?\s*([0-9]+(?:\.[0-9]+)?)\s*mm\b/u', $text, $m)) {
            $parts[] = (str_starts_with(ltrim($m[0]), 'L') ? 'L ' : 'H ').$m[1].' mm';
        }

        if (preg_match('/mm,\s*([+-]?\s?\d+|XS|S|M|L|XL|XXL)\s*,\s*Taper/iu', $text, $m) || preg_match('/\bNECK\s+(S|M|L|XL|LARGE)\b/iu', $text, $m)) {
            $parts[] = __('Neck').' '.preg_replace('/\s+/', '', $m[1]);
        }

        if (preg_match('/\bSize\s+(EXTRA\s+SMALL|EXTRA\s+LARGE|X\s?SMALL|SMALL|MEDIUM|LARGE|X\s?LARGE|[0-9]+)\b/iu', $text, $m) && ! preg_match('/#/', $text)) {
            $parts[] = __('Size').' '.ucwords(mb_strtolower(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]));
        }

        if (preg_match('/\b(right|left)\b/iu', $text, $m)) {
            $parts[] = mb_strtolower($m[1]) === 'right' ? __('Right') : __('Left');
        }

        $size = implode(' · ', array_unique($parts));

        return $size === '' ? null : mb_substr($size, 0, 64);
    }

    public function clean(string $text): string
    {
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);

        foreach (self::TYPOS as $pattern => $fix) {
            $text = preg_replace($pattern, $fix, $text) ?? $text;
        }

        $text = preg_replace('/(?<=[A-Za-z])(dia\.)/u', ' $1', $text) ?? $text;

        // Box makers glued to the name by the source system ("…ALL IN ONEHPF").
        return preg_replace('/(?<=[A-Za-z0-9.)])(HPF|PARAGON MEDICAL|MAHE MEDICAL)$/', ' $1', $text) ?? $text;
    }

    private function similarity(string $a, string $b): float
    {
        // Item words only: sizes, materials and plurals differ between two spellings of one item.
        $words = fn (string $s) => array_unique(array_map(
            fn (string $w) => preg_replace('/S$/', '', $w) ?? $w,
            array_filter(preg_split('/[^A-Z]+/', mb_strtoupper($s), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn (string $w) => mb_strlen($w) >= 3),
        ));
        [$x, $y] = [$words($a), $words($b)];
        $union = count(array_unique(array_merge($x, $y)));

        return $union === 0 ? 1.0 : count(array_intersect($x, $y)) / $union;
    }

    private function code(Worksheet $sheet, int $row): ?string
    {
        $code = $this->clean($this->cell($sheet, 'B', $row));

        // A code: one word with a digit (Lima 4250.20.080, EAN-13, H0050051500, ASC000…).
        return preg_match('/^[.0-9A-Z][0-9A-Z._\-]{3,24}$/i', $code) && preg_match('/\d/', $code) ? mb_strtoupper($code) : null;
    }

    private function isBlank(Worksheet $sheet, int $row): bool
    {
        return trim($this->cell($sheet, 'B', $row).$this->cell($sheet, 'C', $row)) === '';
    }

    private function isSetCode(string $code): bool
    {
        return (bool) preg_match('/000[0-9A-Z]?$/', $code);
    }

    private function isOnRequestHeading(string $text): bool
    {
        return (bool) preg_match('/\b(upon request|on request|additional)\b/i', $text);
    }

    private function isHeading(Worksheet $sheet, int $row): bool
    {
        return $sheet->getStyle("C{$row}")->getFont()->getBold()
            || $sheet->getStyle("B{$row}")->getFont()->getBold()
            || $this->fillOf($sheet, 'C', $row) === '000000';
    }

    private function fill(Worksheet $sheet, int $row): ?string
    {
        return $this->fillOf($sheet, 'C', $row) ?? $this->fillOf($sheet, 'B', $row);
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

    private function area(string $title): ?string
    {
        return match (true) {
            (bool) preg_match('/\bhip\b/i', $title) => 'HIP',
            (bool) preg_match('/\bknee\b/i', $title) => 'KNEE',
            (bool) preg_match('/\bshoulder\b/i', $title) => 'SHOULDER',
            default => null,
        };
    }

    private function sheetKind(string $title): ?string
    {
        return match (true) {
            (bool) preg_match('/\bimpla/i', $title) => 'implants',
            (bool) preg_match('/\bsummary\b/i', $title) => 'summary',
            (bool) preg_match('/\bdetail/i', $title) => 'details',
            default => null,
        };
    }
}

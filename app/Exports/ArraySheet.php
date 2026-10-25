<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet from plain arrays (templates, exports, error files). Codes and dates are
 * written as text so Excel never turns "0871…" into a number or reformats YYYY-MM-DD.
 */
class ArraySheet implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $textColumns  1-based columns forced to text
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $textColumns = [],
        private readonly bool $rightToLeft = false,
    ) {}

    public function array(): array
    {
        return array_map(function (array $row) {
            foreach ($this->textColumns as $column) {
                $index = $column - 1;

                if (isset($row[$index]) && $row[$index] !== '') {
                    $row[$index] = (string) $row[$index];
                }
            }

            return $row;
        }, $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        $last = max(1, count($this->rows) + 1);

        foreach ($this->textColumns as $column) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->getStyle("{$letter}2:{$letter}".max(2, $last + 500))->getNumberFormat()->setFormatCode('@');
        }

        if ($this->rightToLeft) {
            $sheet->setRightToLeft(true);
        }

        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}

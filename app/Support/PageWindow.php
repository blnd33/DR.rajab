<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The page numbers under (and above) a list, the doctor's way: the first and the last page always,
 * and two pages each side of the current one, with "…" for what lies between —
 * page 5 of 77: 1 … 3 4 5 6 7 … 77; page 6: 1 … 4 5 6 7 8 … 77.
 * Same shape as Laravel's own list (arrays of page => url, and "..." strings), so the page bar
 * draws it unchanged.
 */
final class PageWindow
{
    public const AROUND = 2;

    /**
     * @return list<array<int, string>|string>
     */
    public static function elements(LengthAwarePaginator $paginator): array
    {
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $from = max(1, $current - self::AROUND);
        $to = min($last, $current + self::AROUND);
        $pages = fn (int $a, int $b): array => collect(range($a, $b))->mapWithKeys(fn (int $p) => [$p => $paginator->url($p)])->all();
        $elements = [];

        if ($from > 1) {
            $elements[] = $pages(1, 1);

            if ($from > 2) {
                $elements[] = '...';
            }
        }

        $elements[] = $pages($from, $to);

        if ($to < $last) {
            if ($to < $last - 1) {
                $elements[] = '...';
            }

            $elements[] = $pages($last, $last);
        }

        return $elements;
    }
}

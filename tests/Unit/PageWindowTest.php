<?php

use App\Support\PageWindow;
use Illuminate\Pagination\LengthAwarePaginator;

/*
 * Page numbers: the first and last page always, two pages each side of the current one, "…" between.
 */

function pagesShown(int $current, int $last): string
{
    $paginator = new LengthAwarePaginator([], $last * 10, 10, $current);

    return collect(PageWindow::elements($paginator))
        ->map(fn ($e) => is_string($e) ? '…' : implode(' ', array_keys($e)))
        ->implode(' ');
}

it('keeps the first and last page and shows two pages each side of the current one', function () {
    expect(pagesShown(5, 77))->toBe('1 … 3 4 5 6 7 … 77')
        ->and(pagesShown(6, 77))->toBe('1 … 4 5 6 7 8 … 77')
        ->and(pagesShown(1, 77))->toBe('1 2 3 … 77')
        ->and(pagesShown(3, 77))->toBe('1 2 3 4 5 … 77')
        ->and(pagesShown(4, 77))->toBe('1 2 3 4 5 6 … 77')
        ->and(pagesShown(74, 77))->toBe('1 … 72 73 74 75 76 77')
        ->and(pagesShown(77, 77))->toBe('1 … 75 76 77')
        ->and(pagesShown(2, 3))->toBe('1 2 3')
        ->and(pagesShown(1, 1))->toBe('1');
});

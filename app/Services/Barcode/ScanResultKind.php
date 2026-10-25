<?php

namespace App\Services\Barcode;

enum ScanResultKind: string
{
    /** An existing serial-tracked unit. */
    case Unit = 'unit';

    /** Product known, serial not in the system yet → can be received. */
    case NewUnit = 'new_unit';

    /** A lot-tracked product + lot (existing buckets, if any). */
    case Lot = 'lot';

    /** Only the product is known. */
    case Product = 'product';

    /** A plain code that is a lot number (recall-style list). */
    case LotSearch = 'lot_search';

    case Unknown = 'unknown';
}

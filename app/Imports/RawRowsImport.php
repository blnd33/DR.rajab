<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Read the first sheet as raw cell values (no heading row magic, no formatting): the
 * import pipeline maps headers itself and parses Excel dates / numbers with CellParser.
 */
class RawRowsImport implements WithCalculatedFormulas {}

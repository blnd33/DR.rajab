<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * The uploaded file as a whole cannot be imported (empty, wrong columns, too big).
 */
class ImportFileInvalid extends RuntimeException {}

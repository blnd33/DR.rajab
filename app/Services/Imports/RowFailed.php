<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * A confirmed row could not be written (data changed since the preview). The message is
 * shown in the error file.
 */
class RowFailed extends RuntimeException {}

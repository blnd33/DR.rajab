<?php

namespace App\Exceptions\Domain;

use App\Models\Branch;
use RuntimeException;

class BranchClosureBlocked extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly Branch $branch, public readonly array $reasons)
    {
        parent::__construct(__('Branch :code cannot be closed yet.', ['code' => $branch->code]));
    }
}

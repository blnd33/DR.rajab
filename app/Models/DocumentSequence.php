<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Counter row per (document type, branch, year). Only DocumentNumberGenerator writes here.
 */
class DocumentSequence extends Model
{
    protected $fillable = ['type', 'branch_id', 'year', 'next_number'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}

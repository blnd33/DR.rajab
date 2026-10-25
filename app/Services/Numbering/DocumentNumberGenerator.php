<?php

namespace App\Services\Numbering;

use App\Models\Branch;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers: {TYPE}-{BRANCH}-{YEAR}-{00042} (or {TYPE}-{YEAR}-{00042} for global types).
 * Always Western digits. Must be called inside the same transaction as the document insert,
 * so a rolled-back document also rolls back its number.
 */
class DocumentNumberGenerator
{
    public function next(string $type, ?Branch $branch = null, ?int $year = null, int $pad = 5): string
    {
        $type = strtoupper($type);
        $year ??= (int) now(config('orthotrack.timezone'))->format('Y');

        $number = DB::transaction(function () use ($type, $branch, $year) {
            $attributes = ['type' => $type, 'branch_id' => $branch?->getKey(), 'year' => $year];

            // Create the counter row if missing, then lock it.
            DocumentSequence::query()->insertOrIgnore($attributes + [
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::query()
                ->where('type', $type)
                ->where('year', $year)
                ->when(
                    $branch,
                    fn ($q) => $q->where('branch_id', $branch->getKey()),
                    fn ($q) => $q->whereNull('branch_id'),
                )
                ->lockForUpdate()
                ->firstOrFail();

            $current = $sequence->next_number;
            $sequence->increment('next_number');

            return $current;
        });

        $parts = array_filter([$type, $branch?->code, (string) $year, str_pad((string) $number, $pad, '0', STR_PAD_LEFT)]);

        return implode('-', $parts);
    }
}

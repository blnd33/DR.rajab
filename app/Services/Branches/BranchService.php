<?php

namespace App\Services\Branches;

use App\Enums\BranchStatus;
use App\Exceptions\Domain\BranchClosureBlocked;
use App\Models\Branch;
use App\Models\User;
use App\Services\Branches\Contracts\BranchDependencyCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branch lifecycle: create, edit, close (= "delete" in the UI), reopen,
 * and a true delete only for a branch with no records at all.
 */
class BranchService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $branch = new Branch($data);
            $branch->status = BranchStatus::Active;
            $branch->save();

            return $branch;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        $branch->fill($data)->save();

        return $branch;
    }

    /**
     * @return list<string> human-readable reasons; empty = can close
     */
    public function closureBlockers(Branch $branch): array
    {
        $blockers = [];

        foreach ($this->checks() as $check) {
            if (($reason = $check->closureBlocker($branch)) !== null) {
                $blockers[] = $reason;
            }
        }

        return $blockers;
    }

    public function close(Branch $branch, string $reason, User $by): Branch
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        return DB::transaction(function () use ($branch, $reason, $by) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if (! $branch->isActive()) {
                return $branch;
            }

            $blockers = $this->closureBlockers($branch);

            if ($blockers !== []) {
                throw new BranchClosureBlocked($branch, $blockers);
            }

            $branch->forceFill([
                'status' => BranchStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'close_reason' => $reason,
            ])->save();

            return $branch;
        });
    }

    public function reopen(Branch $branch, User $by): Branch
    {
        $branch->forceFill([
            'status' => BranchStatus::Active,
            'reopened_at' => now(),
            'reopened_by' => $by->id,
        ])->save();

        return $branch;
    }

    public function canHardDelete(Branch $branch): bool
    {
        foreach ($this->checks() as $check) {
            if ($check->hasRecords($branch)) {
                return false;
            }
        }

        return true;
    }

    public function hardDelete(Branch $branch): void
    {
        DB::transaction(function () use ($branch) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if (! $this->canHardDelete($branch)) {
                throw ValidationException::withMessages([
                    'branch' => __('This station has records. Close it instead — history is kept.'),
                ]);
            }

            activity('branches')->performedOn($branch)->event('deleted')
                ->withProperties(['code' => $branch->code, 'name' => $branch->getTranslations('name')])
                ->log('Branch deleted (created by mistake)');

            $branch->delete();
        });
    }

    /**
     * @return list<BranchDependencyCheck>
     */
    private function checks(): array
    {
        return array_map(fn (string $class) => app($class), config('orthotrack.branch_checks', []));
    }
}

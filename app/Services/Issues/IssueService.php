<?php

namespace App\Services\Issues;

use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * One queue of things the Super Admin must resolve (transfer / count differences,
 * expired overrides, unaccounted implants…). Feeds the dashboard.
 */
class IssueService
{
    /**
     * Open an issue — or update the open one for the same subject and type.
     *
     * @param  array<string, mixed>  $details
     */
    public function open(IssueType $type, IssueSeverity $severity, ?int $branchId, ?Model $subject, string $title, array $details = []): Issue
    {
        $existing = $subject === null ? null : Issue::query()
            ->open()
            ->where('type', $type)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        $issue = $existing ?? new Issue(['type' => $type, 'status' => IssueStatus::Open]);
        $issue->fill(['severity' => $severity, 'branch_id' => $branchId, 'title' => mb_substr($title, 0, 191), 'details' => $details]);

        if ($subject !== null) {
            $issue->subject()->associate($subject);
        }

        $issue->save();

        return $issue;
    }

    public function resolve(Issue $issue, string $note, User $by): Issue
    {
        return $this->close($issue, IssueStatus::Resolved, $note, $by);
    }

    public function dismiss(Issue $issue, string $note, User $by): Issue
    {
        return $this->close($issue, IssueStatus::Dismissed, $note, $by);
    }

    /**
     * Close the open issue of a subject when its document no longer has differences.
     */
    public function resolveFor(Model $subject, IssueType $type, string $note, User $by): void
    {
        Issue::query()->open()->where('type', $type)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->get()
            ->each(fn (Issue $issue) => $issue->forceFill([
                'status' => IssueStatus::Resolved,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save());
    }

    private function close(Issue $issue, IssueStatus $status, string $note, User $by): Issue
    {
        if (! $by->can('issues.resolve')) {
            throw new AuthorizationException(__('You are not allowed to resolve issues.'));
        }

        $issue->forceFill([
            'status' => $status,
            'resolved_by' => $by->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();

        return $issue;
    }
}

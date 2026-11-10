<?php

namespace App\Services\Insight\Facts;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Support\Locale\Display;

/**
 * Open issues (unaccounted implants, count differences, …), newest first.
 */
class OpenIssues extends Fact
{
    public static function key(): string
    {
        return 'open-issues';
    }

    public function title(): string
    {
        return __('Open issues');
    }

    public function section(): FactSection
    {
        return FactSection::Attention;
    }

    protected function permission(): string
    {
        return 'issues.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $query = $context->scope(Issue::query(), 'issues.view_any')->where('status', IssueStatus::Open);
        $total = (clone $query)->count();
        $lines = $query->latest('id')->limit(self::MAX_LINES)->get()
            ->map(fn (Issue $issue) => self::line($issue->title, Display::date($issue->created_at)))->all();

        return $this->result($context, self::count($total), $lines, ['issues' => $total],
            'filament.admin.resources.issues.index',
            tone: $total > 0 ? FactResult::DANGER : FactResult::GOOD);
    }
}

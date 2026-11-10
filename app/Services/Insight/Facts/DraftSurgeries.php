<?php

namespace App\Services\Insight\Facts;

use App\Enums\SurgeryStatus;
use App\Models\Surgery;
use App\Services\Clinical\SurgeryService;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Support\Locale\Display;

/**
 * Surgeries saved as drafts and not finished (a scan problem, a missing item…), oldest first.
 */
class DraftSurgeries extends Fact
{
    public function __construct(private readonly SurgeryService $surgeries) {}

    public static function key(): string
    {
        return 'draft-surgeries';
    }

    public function title(): string
    {
        return __('Surgeries not finished');
    }

    public function section(): FactSection
    {
        return FactSection::Attention;
    }

    protected function permission(): string
    {
        return 'surgeries.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $query = $context->scope($this->surgeries->visibleTo($context->user), 'surgeries.view_any')
            ->with('customer')
            ->where('status', SurgeryStatus::Draft);
        $total = (clone $query)->count();
        $lines = $query->orderBy('surgery_date')->limit(self::MAX_LINES)->get()
            ->map(fn (Surgery $s) => self::line($s->number.' · '.($s->customer->name ?? __('No hospital')), Display::date($s->surgery_date)))->all();

        return $this->result($context, self::count($total), $lines, ['surgeries' => $total],
            'filament.admin.resources.surgeries.index',
            tone: $total > 0 ? FactResult::WARNING : FactResult::GOOD);
    }
}

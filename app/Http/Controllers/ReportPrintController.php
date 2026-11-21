<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportRegistry;
use App\Support\Locale\Display;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Print page (and PDF) of a report with the filters from the screen (P5-3).
 */
class ReportPrintController extends Controller
{
    public function __invoke(Request $request, string $report, PdfRenderer $pdf): Response
    {
        /** @var User $user */
        $user = $request->user();
        $definition = ReportRegistry::find($report);
        abort_if($definition === null, 404);
        abort_unless(ReportRegistry::canOpen($user, $definition), 403);

        $context = ReportContext::make($definition, $user, $request->only(['from', 'until', 'branch', 'currency', 'group', 'idle']));
        $rows = $definition->rows($context);
        $columns = $definition->visibleColumns($context);
        $branch = $context->branchIds !== null && count($context->branchIds) === 1 ? Branch::query()->find($context->branchIds[0]) : null;

        $meta = array_filter([
            __('Period') => in_array('period', $definition->filters(), true) ? Display::date($context->from).' → '.Display::date($context->until) : Display::date(now()),
            __('Station') => $branch->name ?? __('All my stations'),
            __('Amounts in') => in_array('currency', $definition->filters(), true) ? $context->currency : null,
            __('Group by') => $context->group !== null ? ($definition->groups()[$context->group] ?? null) : null,
        ]);

        $html = view('print.report', [
            'report' => $definition,
            'context' => $context,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $definition->totals($context, $rows),
            'meta' => $meta,
            'pdfUrl' => $pdf->available() ? $request->fullUrlWithQuery(['pdf' => 1]) : null,
        ])->render();

        if ($request->boolean('pdf')) {
            $download = $pdf->download($html, ReportRegistry::filename($definition, $context).'.pdf', count($columns) > 6);

            if ($download !== null) {
                return $download;
            }
        }

        return response($html);
    }
}

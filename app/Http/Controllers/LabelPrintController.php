<?php

namespace App\Http\Controllers;

use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Labels\LabelRenderer;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Printable label sheet (one label per page, page = label size) for the browser's print
 * dialog → thermal printer. Signed URL; every item is still permission-checked.
 */
class LabelPrintController extends Controller
{
    public const KINDS = ['product', 'unit', 'batch'];

    public const MAX_LABELS = 500;

    /**
     * @param  list<int>  $ids
     */
    public static function url(string $kind, array $ids, ?int $templateId = null, int $copies = 1): string
    {
        return URL::temporarySignedRoute('labels.print', now()->addHour(), [
            'kind' => $kind,
            'ids' => implode(',', array_slice(array_map('intval', $ids), 0, self::MAX_LABELS)),
            'template' => $templateId,
            'copies' => max(1, min(50, $copies)),
        ]);
    }

    public function __invoke(Request $request, LabelRenderer $renderer, BranchScopeResolver $scope): View
    {
        /** @var User $user */
        $user = $request->user();
        $kind = (string) $request->query('kind');
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids')))));
        abort_if($ids === [], 404);

        $template = $request->filled('template')
            ? LabelTemplate::query()->findOrFail((int) $request->query('template'))
            : LabelTemplate::default();
        abort_if($template === null, 404, __('No label template yet. Add one in Settings → Label templates.'));

        $copies = max(1, min(50, (int) $request->query('copies', 1)));

        $items = match ($kind) {
            'product' => $this->products($ids, $user, $scope)->map(fn (Product $p) => $renderer->forProduct($template, $p)),
            'unit' => StockUnit::query()->with(['product', 'branch'])->whereIn('id', $ids)->get()
                ->each(fn (StockUnit $u) => abort_unless($user->can('printLabel', $u), 403))
                ->map(fn (StockUnit $u) => $renderer->forUnit($template, $u)),
            'batch' => StockBatch::query()->with(['product', 'branch'])->whereIn('id', $ids)->get()
                ->each(fn (StockBatch $b) => abort_unless($user->can('printLabel', $b), 403))
                ->map(fn (StockBatch $b) => $renderer->forBatch($template, $b)),
        };

        $labels = $items->flatMap(fn ($item) => array_fill(0, $copies, $item))->take(self::MAX_LABELS)->values();

        activity('labels')->causedBy($user)->event('printed')
            ->withProperties(['kind' => $kind, 'ids' => $ids, 'template' => $template->id, 'copies' => $copies])
            ->log('Labels printed');

        return view('labels.print', [
            'template' => $template,
            'labels' => $labels,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function products(array $ids, User $user, BranchScopeResolver $scope)
    {
        abort_unless($scope->hasAny($user, 'products.print_labels'), 403);

        return Product::query()->whereIn('id', $ids)->orderBy('sku')->get();
    }
}

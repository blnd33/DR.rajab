<?php

namespace App\Filament\Admin\Pages;

use App\Models\Branch;
use App\Models\User;
use App\Services\Graphs\Graph;
use App\Services\Graphs\GraphContext;
use App\Services\Graphs\GraphData;
use App\Services\Graphs\GraphRegistry;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * One graph: period, branch and currency filters, the chart, and a table with every value
 * and its share of the total.
 */
class ViewGraph extends Page
{
    protected static ?string $slug = 'graphs/view';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.view-graph';

    #[Url]
    public string $graph = '';

    #[Url(except: '')]
    public ?string $from = '';

    #[Url(except: '')]
    public ?string $until = '';

    #[Url(except: '')]
    public ?string $branch = '';

    #[Url(except: 'USD')]
    public ?string $currency = 'USD';

    /** Pie, bars or line; empty = the graph's own form. */
    #[Url(as: 'view', except: '')]
    public string $chartView = '';

    private ?GraphData $cachedData = null;

    private ?string $cachedHash = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && GraphRegistry::canUse($user);
    }

    public function mount(): void
    {
        $graph = app(GraphRegistry::class)->find($this->graph);
        abort_if($graph === null, 404);
        abort_unless(app(GraphRegistry::class)->canSee($this->user(), $graph), 403);

        if ($this->from === '' && $graph->usesPeriod) {
            $this->from = GraphContext::defaultFrom()->toDateString();
        }
    }

    public function definition(): Graph
    {
        return app(GraphRegistry::class)->find($this->graph) ?? abort(404);
    }

    public function getTitle(): string
    {
        return $this->definition()->title;
    }

    public function getSubheading(): ?string
    {
        return $this->definition()->description;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('all')
                ->label(__('All graphs'))
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(Graphs::getUrl()),
        ];
    }

    public function context(): GraphContext
    {
        return GraphContext::make($this->user(), [
            'from' => $this->from ?: null,
            'until' => $this->until ?: null,
            'branch' => $this->branch ?: null,
            'currency' => $this->currency,
        ]);
    }

    public function data(): GraphData
    {
        $hash = md5($this->graph.'|'.$this->from.'|'.$this->until.'|'.$this->branch.'|'.$this->currency);

        if ($this->cachedData === null || $this->cachedHash !== $hash) {
            $this->cachedData = app(GraphRegistry::class)->build($this->definition(), $this->context());
            $this->cachedHash = $hash;
        }

        return $this->cachedData;
    }

    /**
     * Same pickers as the reports (dates shown YYYY-MM-DD).
     */
    public function filtersForm(Schema $schema): Schema
    {
        $definition = $this->definition();
        $allowed = app(BranchScopeResolver::class)->branchIdsFor($this->user(), 'reports.view');
        $branches = Branch::query()->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Branch $b) => [(string) $b->id => $b->code.' · '.$b->name])->all();

        return $schema
            ->components([
                DatePicker::make('from')->label(__('From'))->native(false)->displayFormat('Y-m-d')->live()->visible($definition->usesPeriod),
                DatePicker::make('until')->label(__('Until'))->native(false)->displayFormat('Y-m-d')->live()->visible($definition->usesPeriod),
                Select::make('branch')->label(__('Branch'))->placeholder(__('All branches'))->options($branches)->native(false)->live()->visible(count($branches) > 1),
                Select::make('currency')->label(__('Show in'))->options(['USD' => 'USD', 'EUR' => 'EUR', 'IQD' => 'IQD'])->selectablePlaceholder(false)->native(false)->live()->visible($definition->usesCurrency),
            ])
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4]);
    }

    public function updated(string $property): void
    {
        // A cleared picker comes back as null.
        foreach (['from', 'until', 'branch'] as $field) {
            $this->{$field} = (string) ($this->{$field} ?? '');
        }

        $this->currency = in_array($this->currency, ['USD', 'EUR', 'IQD'], true) ? $this->currency : 'USD';
    }

    public function chooseView(string $view): void
    {
        if (in_array($view, $this->definition()->views(), true)) {
            $this->chartView = $view === $this->definition()->defaultView() ? '' : $view;
        }
    }

    /**
     * The form drawn now: the chosen one when it can show these numbers, else the graph's own
     * form (or bars when that is a pie that cannot be drawn).
     */
    public function currentView(): string
    {
        $definition = $this->definition();
        $view = in_array($this->chartView, $definition->views(), true) ? $this->chartView : $definition->defaultView();

        if ($view === Graph::VIEW_PIE && $this->pie()[0] === null) {
            return $definition->defaultView() === Graph::VIEW_PIE ? Graph::VIEW_BARS : $definition->defaultView();
        }

        return $view;
    }

    /**
     * @return array{0: ?GraphData, 1: ?string}
     */
    public function pie(): array
    {
        return app(GraphRegistry::class)->asPie($this->definition(), $this->data());
    }

    public function resetFilters(): void
    {
        $this->from = $this->definition()->usesPeriod ? GraphContext::defaultFrom()->toDateString() : '';
        $this->until = '';
        $this->branch = '';
        $this->currency = 'USD';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $definition = $this->definition();
        $data = $this->data();
        $view = $this->currentView();
        [$pie, $noPie] = $this->pie();
        $drawn = $view === Graph::VIEW_PIE && $pie !== null ? $pie : $data;

        return [
            'definition' => $definition,
            'data' => $data,
            'view' => $view,
            'views' => array_map(fn (string $v) => [
                'key' => $v,
                'label' => Graph::viewLabel($v),
                'icon' => Graph::viewIcon($v),
                'reason' => $v === Graph::VIEW_PIE ? $noPie : null,
            ], $definition->views()),
            'chartKind' => $definition->kindFor($view),
            'drawn' => $drawn,
            'pieNote' => $view === Graph::VIEW_PIE && count($data->series) > 1
                ? __('The pie shows :series only.', ['series' => $data->series[0]['label']])
                : null,
            'chartKey' => md5((string) json_encode([$this->graph, $view, $drawn->labels, $drawn->series, app()->getLocale()])),
        ];
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}

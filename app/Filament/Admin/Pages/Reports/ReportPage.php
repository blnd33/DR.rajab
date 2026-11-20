<?php

namespace App\Filament\Admin\Pages\Reports;

use App\Filament\Admin\Support\ServiceAction;
use App\Models\Currency;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportColumn;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One screen for every report (P5-1): filters above, the rows, a totals line, Excel and print.
 * Subclasses only name their report.
 */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var class-string<Report> */
    protected static string $reportClass;

    protected string $view = 'filament.pages.table-page';

    /** @var array{hash: string, rows: list<array<string, mixed>>, missing: bool}|null */
    private ?array $cachedRows = null;

    public static function report(): Report
    {
        return app(static::$reportClass);
    }

    /** Shown in the Stock menu instead of Reports. */
    protected static bool $inStockMenu = false;

    public static function getNavigationGroup(): ?string
    {
        return static::$inStockMenu ? __('Stock') : __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return static::report()->title();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'reports/'.(static::$reportClass)::key();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ReportRegistry::canOpen($user, static::report());
    }

    public function getTitle(): string
    {
        return static::report()->title();
    }

    public function getSubheading(): ?string
    {
        $this->rows();

        return trim(implode(' ', array_filter([
            static::report()->description(),
            ($this->cachedRows['missing'] ?? false) ? __('Some amounts could not be converted — set the exchange rates.') : null,
        ]))) ?: null;
    }

    /**
     * The filters as a flat array (same shape as the print page's query string).
     *
     * @return array<string, mixed>
     */
    protected function flatFilters(): array
    {
        $filters = $this->tableFilters ?? [];

        return [
            'from' => $filters['period']['from'] ?? null,
            'until' => $filters['period']['until'] ?? null,
            'branch' => $filters['branch']['value'] ?? null,
            'currency' => $filters['currency']['value'] ?? null,
            'group' => $filters['group']['value'] ?? null,
        ];
    }

    protected function context(): ReportContext
    {
        return ReportContext::make(static::report(), ServiceAction::user(), $this->flatFilters());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        $hash = md5((string) json_encode($this->flatFilters()));

        if ($this->cachedRows === null || $this->cachedRows['hash'] !== $hash) {
            $context = $this->context();
            $this->cachedRows = ['hash' => $hash, 'rows' => static::report()->rows($context), 'missing' => $context->missingRates];
        }

        return $this->cachedRows['rows'];
    }

    public function table(Table $table): Table
    {
        $report = static::report();
        $context = $this->context();

        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection) use ($report, $context) {
                $rows = collect($this->rows());

                if ($sortColumn !== null) {
                    $column = collect($report->visibleColumns($context))->firstWhere('name', $sortColumn);
                    $numeric = $column?->isNumeric() ?? false;
                    $rows = $rows->sort(function (array $a, array $b) use ($sortColumn, $numeric) {
                        $x = $a[$sortColumn] ?? null;
                        $y = $b[$sortColumn] ?? null;

                        return $numeric ? ((float) $x <=> (float) $y) : strnatcasecmp((string) $x, (string) $y);
                    });

                    if ($sortDirection === 'desc') {
                        $rows = $rows->reverse();
                    }
                }

                return $rows->values();
            })
            ->columns(array_map(fn (ReportColumn $c) => $this->column($c, $context), $report->visibleColumns($context)))
            ->filters($this->filters($report))
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->deferFilters(false)
            ->paginated(false)
            ->contentFooter(fn () => view('filament.reports.totals', [
                'reportColumns' => $report->visibleColumns($context),
                'reportTotals' => $report->totals($context, $this->rows()),
                'reportContext' => $context,
            ]))
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading(__('Nothing in this period'))
            ->emptyStateDescription(__('Change the dates or the station.'));
    }

    private function column(ReportColumn $column, ReportContext $context): TextColumn
    {
        $text = TextColumn::make($column->name)
            ->label($column->type === ReportColumn::MONEY && $column->currencyKey === null ? "{$column->label} ({$context->currency})" : $column->label)
            ->sortable()
            ->state(fn (array $record) => ReportRegistry::display($column, $record, $context));

        if ($column->isNumeric()) {
            $text->alignEnd();
        }

        if ($column->type === ReportColumn::CODE) {
            $text->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']);
        }

        if ($column->type === ReportColumn::MONEY) {
            $text->extraAttributes(['dir' => 'ltr', 'class' => 'whitespace-nowrap']);
        }

        return $text;
    }

    /**
     * @return list<Filter|SelectFilter>
     */
    private function filters(Report $report): array
    {
        $filters = [];
        $wanted = $report->filters();

        if (in_array('period', $wanted, true)) {
            $filters[] = Filter::make('period')
                ->schema([
                    DatePicker::make('from')->label(__('From'))->native(false)->displayFormat('Y-m-d')
                        ->default(fn () => $report->defaultFrom()->toDateString()),
                    DatePicker::make('until')->label(__('Until'))->native(false)->displayFormat('Y-m-d')
                        ->default(fn () => now(config('orthotrack.timezone'))->toDateString()),
                ])
                ->columns(2)
                ->columnSpan(['default' => 1, 'sm' => 2]);
        }

        if (in_array('branch', $wanted, true)) {
            $filters[] = SelectFilter::make('branch')->label(__('Station'))
                ->options(fn () => ServiceAction::branchOptions('reports.view'))
                ->placeholder(__('All my stations'));
        }

        if (in_array('currency', $wanted, true)) {
            $filters[] = SelectFilter::make('currency')->label(__('Show amounts in'))
                ->options(fn () => Currency::options())->default('USD')->selectablePlaceholder(false);
        }

        if (in_array('group', $wanted, true) && $report->groups() !== []) {
            $filters[] = SelectFilter::make('group')->label(__('Group by'))
                ->options($report->groups())->default(array_key_first($report->groups()))->selectablePlaceholder(false);
        }

        return $filters;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('Print / PDF'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => route('reports.print', ['report' => (static::$reportClass)::key()] + $this->context()->toQuery()))
                ->openUrlInNewTab(),
            Action::make('export')
                ->label(__('Export to Excel'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn () => ReportRegistry::canExport(ServiceAction::user()))
                ->action(function () {
                    $context = $this->context();

                    return Excel::download(ReportRegistry::sheet(static::report(), $context), ReportRegistry::filename(static::report(), $context));
                }),
        ];
    }
}

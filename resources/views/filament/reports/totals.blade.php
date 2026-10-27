{{-- Totals strip shown above a report table (Filament renders the content footer there). --}}
@php use App\Services\Reports\ReportRegistry; @endphp
@if ($reportTotals !== [])
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-gray-200 px-4 py-3 text-sm dark:border-white/10">
        <span class="font-semibold">{{ __('Total') }}</span>
        @foreach ($reportColumns as $column)
            @isset($reportTotals[$column->name])
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-gray-500 dark:text-gray-400">{{ $column->label }}:</span>
                    <span class="font-semibold" dir="ltr">{{ ReportRegistry::display($column, $reportTotals, $reportContext) }}</span>
                </span>
            @endisset
        @endforeach
    </div>
@endif

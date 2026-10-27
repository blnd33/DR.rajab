@php
    use App\Services\Reports\ReportColumn;
    use App\Services\Reports\ReportRegistry;
@endphp
<x-print.layout :title="$report->title()" :meta="$meta" :pdf-url="$pdfUrl" :landscape="count($columns) > 6">
    @if ($report->description())
        <p class="muted">{{ $report->description() }}</p>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['num' => $column->isNumeric()])>
                        {{ $column->label }}@if ($column->type === ReportColumn::MONEY && $column->currencyKey === null) ({{ $context->currency }})@endif
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $column)
                        <td @class(['num' => $column->isNumeric(), 'code' => $column->type === ReportColumn::CODE, 'ltr' => $column->type === ReportColumn::MONEY])>
                            {{ ReportRegistry::display($column, $row, $context) }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" class="muted">{{ __('Nothing in this period') }}</td></tr>
            @endforelse
        </tbody>
        @if ($totals !== [] && $rows !== [])
            <tfoot>
                <tr>
                    @foreach ($columns as $i => $column)
                        <td @class(['num' => $column->isNumeric(), 'ltr' => $column->type === ReportColumn::MONEY])>
                            @if ($i === 0)
                                {{ __('Total') }}
                            @elseif (isset($totals[$column->name]))
                                {{ ReportRegistry::display($column, $totals, $context) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</x-print.layout>

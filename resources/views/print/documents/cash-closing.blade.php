@php
    use App\Support\Locale\Display;
    /** @var \App\Models\CashBoxClosing $record */
    $record->loadMissing(['cashBox.branch', 'counter', 'reviewer']);
    $currency = $record->cashBox->currency;
@endphp
<x-print.layout :title="__('Cash closing sheet')" :pdf-url="$pdfUrl" :meta="array_filter([
    __('Box') => $record->cashBox->label(),
    __('Day') => Display::date($record->business_date),
    __('Status') => $record->status->getLabel(),
    __('Counted by') => $record->counter?->name,
    __('Reviewed by') => $record->reviewer?->name,
])">
    <table>
        <tbody>
            <tr><td>{{ __('At the start of the day') }}</td><td class="num ltr">{{ Display::money($record->opening_balance, $currency) }}</td></tr>
            <tr><td>{{ __('Expected') }}</td><td class="num ltr">{{ Display::money($record->expected_balance, $currency) }}</td></tr>
            <tr><td>{{ __('Counted') }}</td><td class="num ltr">{{ $record->counted_balance !== null ? Display::money($record->counted_balance, $currency) : '—' }}</td></tr>
        </tbody>
        <tfoot>
            <tr><td>{{ __('Difference') }}</td><td class="num ltr">{{ Display::money($record->difference, $currency) }}</td></tr>
        </tfoot>
    </table>
    @if ($record->denominations)
        <section>
            <h2>{{ __('Notes counted') }}</h2>
            <table>
                <thead><tr><th>{{ __('Note') }}</th><th class="num">{{ __('How many') }}</th></tr></thead>
                <tbody>
                    @foreach ($record->denominations as $note => $count)
                        <tr><td class="code">{{ $note }}</td><td class="num">{{ Display::number($count) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
    @if ($record->difference_reason)
        <section><h2>{{ __('Reason for a difference') }}</h2><p>{{ $record->difference_reason }}</p></section>
    @endif
    @if ($record->review_note)
        <section><h2>{{ __('Review note') }}</h2><p>{{ $record->review_note }}</p></section>
    @endif
    <div class="signatures"><div>{{ __('Cashier') }}</div><div>{{ __('Manager') }}</div></div>
</x-print.layout>

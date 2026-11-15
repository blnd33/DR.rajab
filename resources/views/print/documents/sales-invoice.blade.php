@php
    use App\Support\Locale\Display;
    /** @var \App\Models\SalesInvoice $record */
    $record->loadMissing(['branch', 'customer', 'items.product', 'items.stockUnit', 'items.stockBatch', 'creator', 'surgery.type', 'surgery.patient']);
    $currency = $record->currency;
    $isSurgery = $record->kind === \App\Enums\InvoiceKind::Surgery;
@endphp
<x-print.layout :title="($isSurgery ? __('Invoice') : __('Sales invoice')).' '.$record->number" :pdf-url="$pdfUrl" :meta="array_filter([
    __('Number') => $record->number,
    __('Date') => Display::date($record->issued_at),
    __('Branch') => $record->branch->name,
    __('Buyer') => $record->customer->name ?? ($record->walk_in_name ?: __('Walk-in')),
    __('Terms') => $record->payment_terms->getLabel(),
    __('Due') => $record->due_date ? Display::date($record->due_date) : null,
    __('Status') => $record->status === \App\Enums\SalesInvoiceStatus::Issued ? __('Not paid') : $record->status->getLabel(),
])">
    <table>
        <thead>
            <tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Serial / lot') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Price') }}</th><th class="num">{{ __('Line total') }}</th></tr>
        </thead>
        <tbody>
            @if ($isSurgery)
                <tr>
                    <td>{{ Display::number(1) }}</td>
                    <td>{{ __('Surgery') }}: {{ $record->surgery?->type?->name ?? '—' }} <span class="code muted">{{ $record->surgery?->number }}</span>
                        @if ($record->surgery?->patientName()) <br><span class="muted">{{ __('Patient') }}: {{ $record->surgery->patientName() }}</span> @endif
                    </td>
                    <td>{{ $record->surgery ? Display::date($record->surgery->surgery_date) : '—' }}</td>
                    <td class="num">{{ Display::number(1) }}</td>
                    <td class="num ltr">{{ Display::money($record->total, $currency) }}</td>
                    <td class="num ltr">{{ Display::money($record->total, $currency) }}</td>
                </tr>
            @endif
            @foreach ($record->items as $i => $item)
                <tr>
                    <td>{{ Display::number($i + 1) }}</td>
                    <td>{{ $item->product->name }} <span class="code muted">{{ $item->product->sku }}</span></td>
                    <td class="code">{{ $item->stockUnit->serial ?? $item->stockBatch->lot ?? '—' }}</td>
                    <td class="num">{{ Display::number($item->quantity) }}</td>
                    <td class="num ltr">{{ Display::money($item->unit_price, $currency) }}</td>
                    <td class="num ltr">{{ Display::money($item->line_total, $currency) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="5">{{ __('Subtotal') }}</td><td class="num ltr">{{ Display::money($record->subtotal, $currency) }}</td></tr>
            @if ((float) $record->discount > 0)
                <tr><td colspan="5">{{ __('Discount') }}</td><td class="num ltr">− {{ Display::money($record->discount, $currency) }}</td></tr>
            @endif
            <tr><td colspan="5">{{ __('Total') }}</td><td class="num ltr">{{ Display::money($record->total, $currency) }}</td></tr>
            <tr><td colspan="5">{{ __('Paid') }}</td><td class="num ltr">{{ Display::money($record->paid_amount, $currency) }}</td></tr>
        </tfoot>
    </table>
    @if ($record->notes)
        <section><h2>{{ __('Notes') }}</h2><p>{{ $record->notes }}</p></section>
    @endif
    <div class="signatures"><div>{{ __('Seller') }}</div><div>{{ __('Received by') }}</div></div>
</x-print.layout>

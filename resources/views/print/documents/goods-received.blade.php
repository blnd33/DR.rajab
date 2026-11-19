@php
    use App\Support\Locale\Display;
    /** @var \App\Models\PurchaseReceipt $record */
    $record->loadMissing(['purchase.supplier', 'branch', 'receiver', 'items.purchaseItem.product']);
@endphp
<x-print.layout :title="__('Goods received note').' '.$record->number" :pdf-url="$pdfUrl" :meta="[
    __('Number') => $record->number,
    __('Received') => Display::dateTime($record->received_at),
    __('Station') => $record->branch->name,
    __('Supplier') => $record->purchase->supplier->name,
    __('Purchase') => $record->purchase->number,
    __('Supplier invoice no.') => $record->purchase->supplier_invoice_number,
    __('By') => $record->receiver->name ?? '—',
]">
    <table>
        <thead>
            <tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Serial') }}</th><th>{{ __('Lot') }}</th><th>{{ __('Expiry') }}</th><th class="num">{{ __('Quantity') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($record->items as $i => $item)
                <tr>
                    <td>{{ Display::number($i + 1) }}</td>
                    <td>{{ $item->purchaseItem->product->name }} <span class="code muted">{{ $item->purchaseItem->product->sku }}</span></td>
                    <td class="code">{{ $item->getAttribute('serial') ?? '—' }}</td>
                    <td class="code">{{ $item->getAttribute('lot') ?? '—' }}</td>
                    <td class="code">{{ Display::date($item->expiry_date) ?: '—' }}</td>
                    <td class="num">{{ Display::number($item->quantity) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="5">{{ __('Total') }}</td><td class="num">{{ Display::number($record->items->sum('quantity')) }}</td></tr>
        </tfoot>
    </table>
    <div class="signatures"><div>{{ __('Delivered by') }}</div><div>{{ __('Received by') }}</div></div>
</x-print.layout>

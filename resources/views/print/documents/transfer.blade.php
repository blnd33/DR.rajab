@php
    use App\Support\Locale\Display;
    /** @var \App\Models\Transfer $record */
    $record->loadMissing(['fromBranch', 'toBranch', 'items.product', 'items.stockUnit', 'items.fromBatch', 'sender', 'receiver']);
@endphp
<x-print.layout :title="__('Transfer note').' '.$record->number" :pdf-url="$pdfUrl" :meta="array_filter([
    __('Number') => $record->number,
    __('From') => $record->fromBranch->name,
    __('To') => $record->toBranch->name,
    __('Status') => $record->status->getLabel(),
    __('Sent') => $record->sent_at ? Display::dateTime($record->sent_at).' · '.($record->sender->name ?? '') : null,
    __('Received') => $record->received_at ? Display::dateTime($record->received_at).' · '.($record->receiver->name ?? '') : null,
    __('Carrier') => $record->carrier_note,
])">
    <table>
        <thead>
            <tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Serial / lot') }}</th><th>{{ __('Expiry') }}</th><th class="num">{{ __('Sent') }}</th><th class="num">{{ __('Received') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($record->items as $i => $item)
                <tr>
                    <td>{{ Display::number($i + 1) }}</td>
                    <td>{{ $item->product->name }} <span class="code muted">{{ $item->product->sku }}</span></td>
                    <td class="code">{{ $item->code() }}</td>
                    <td class="code">{{ Display::date($item->stockUnit->expiry_date ?? $item->fromBatch?->expiry_date) ?: '—' }}</td>
                    <td class="num">{{ Display::number($item->quantity_sent) }}</td>
                    <td class="num">{{ $item->quantity_received !== null ? Display::number($item->quantity_received) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">{{ __('Total') }}</td>
                <td class="num">{{ Display::number($record->items->sum('quantity_sent')) }}</td>
                <td class="num">{{ Display::number($record->items->sum('quantity_received')) }}</td>
            </tr>
        </tfoot>
    </table>
    @if ($record->notes)
        <section><h2>{{ __('Notes') }}</h2><p>{{ $record->notes }}</p></section>
    @endif
    <div class="signatures"><div>{{ __('Sent by') }}</div><div>{{ __('Received by') }}</div></div>
</x-print.layout>

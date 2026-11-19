@php
    use App\Support\Locale\Display;
    /** @var \App\Models\KitCheckout $record */
    $record->loadMissing(['kit.type', 'branch', 'customer', 'doctor', 'items.instrument.type', 'items.product', 'items.stockUnit', 'items.stockBatch']);
    $back = $record->returned_at !== null;
@endphp
<x-print.layout :title="__('Instrument set checklist').' '.$record->number" :pdf-url="$pdfUrl" :meta="array_filter([
    __('Instrument set') => $record->kit->code.' · '.($record->kit->type->name ?? ''),
    __('Hospital') => $record->customer->name,
    __('Doctor') => $record->doctor?->name,
    __('Out since') => Display::dateTime($record->checked_out_at),
    __('Due back') => $record->due_back_at ? Display::date($record->due_back_at) : null,
    __('Status') => $record->status->getLabel(),
])">
    <table>
        <thead>
            <tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Code') }}</th><th class="num">{{ __('Out') }}</th><th class="num">{{ __('Back') }}</th><th class="num">{{ __('Used') }}</th><th>✓</th></tr>
        </thead>
        <tbody>
            @foreach ($record->items as $i => $item)
                <tr>
                    <td>{{ Display::number($i + 1) }}</td>
                    <td>{{ $item->instrument ? ($item->instrument->type->name ?? __('Instrument')) : ($item->product->name ?? '—') }}</td>
                    <td class="code">{{ $item->code() }}</td>
                    <td class="num">{{ Display::number($item->qty_out) }}</td>
                    <td class="num">{{ $back ? Display::number($item->qty_returned) : '' }}</td>
                    <td class="num">{{ $back ? Display::number($item->qty_used) : '' }}</td>
                    <td>☐</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($record->notes)
        <section><h2>{{ __('Notes') }}</h2><p>{{ $record->notes }}</p></section>
    @endif
    <div class="signatures"><div>{{ __('Handed over by') }}</div><div>{{ __('Received at the hospital by') }}</div></div>
</x-print.layout>

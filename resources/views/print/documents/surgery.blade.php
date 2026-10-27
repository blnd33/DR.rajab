@php
    use App\Support\Locale\Display;
    /** @var \App\Models\Surgery $record */
    $record->loadMissing(['branch', 'customer', 'patient', 'type', 'doctors', 'liveItems.product', 'liveItems.stockUnit', 'liveItems.stockBatch']);
    $items = $record->liveItems;
@endphp
<x-print.layout :title="__('Surgery report').' '.$record->number" :pdf-url="$pdfUrl" :meta="array_filter([
    __('Number') => $record->number,
    __('Date') => Display::date($record->surgery_date),
    __('Hospital') => $record->customer->name ?? '—',
    __('Surgery type') => $record->type->name ?? '—',
    __('Side') => $record->laterality->getLabel(),
    __('Patient') => $record->patient ? $record->patient->full_name.' ('.$record->patient->file_number.')' : null,
    __('Surgeons') => $record->doctors->pluck('name')->implode(', ') ?: null,
    __('Status') => $record->status->getLabel(),
])">
    <section>
        <h2>{{ __('Implants used') }}</h2>
        <table>
            <thead>
                <tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Serial / lot') }}</th><th>{{ __('Expiry') }}</th><th class="num">{{ __('Quantity') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($items as $i => $item)
                    <tr>
                        <td>{{ Display::number($i + 1) }}</td>
                        <td>{{ $item->product->name }}</td>
                        <td class="code">{{ $item->product->sku }}</td>
                        <td class="code">{{ $item->code() }}</td>
                        <td class="code">{{ Display::date($item->stockUnit->expiry_date ?? $item->stockBatch?->expiry_date) ?: '—' }}</td>
                        <td class="num">{{ Display::number($item->quantity) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('No implants recorded') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($record->notes)
        <section><h2>{{ __('Notes') }}</h2><p>{{ $record->notes }}</p></section>
    @endif
    <div class="signatures"><div>{{ __('Surgeon') }}</div><div>{{ __('Hospital') }}</div></div>
</x-print.layout>

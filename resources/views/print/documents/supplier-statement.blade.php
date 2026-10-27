@php
    use App\Support\Locale\Display;
    use Brick\Math\BigDecimal;
    /** @var \App\Models\Supplier $record */
    $running = [];
    $owed = $balances === [] ? '—' : implode(' · ', array_map(fn ($c, $v) => Display::money($v, $c), array_keys($balances), $balances));
@endphp
<x-print.layout :title="__('Supplier statement').' — '.$record->name" :pdf-url="$pdfUrl" :meta="[
    __('Supplier') => $record->code.' · '.$record->name,
    __('Date') => Display::date(now()),
    __('We owe') => $owed,
]">
    <table>
        <thead>
            <tr><th>{{ __('Date') }}</th><th>{{ __('What') }}</th><th>{{ __('Description') }}</th><th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Balance') }}</th></tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                @php($running[$entry->currency] = BigDecimal::of($running[$entry->currency] ?? '0')->plus($entry->amount))
                <tr>
                    <td class="code">{{ Display::date($entry->occurred_at) }}</td>
                    <td>{{ $entry->entry_type->getLabel() }}</td>
                    <td>{{ $entry->description }}</td>
                    <td class="num ltr">{{ Display::money($entry->amount, $entry->currency) }}</td>
                    <td class="num ltr">{{ Display::money((string) $running[$entry->currency], $entry->currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">{{ __('Nothing on the statement yet') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</x-print.layout>

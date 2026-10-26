{{-- Amount + currency in the user's digit system; the currency code stays Latin and the pair stays LTR. --}}
@props(['value', 'currency'])
<bdi dir="ltr" {{ $attributes->merge(['class' => 'whitespace-nowrap']) }}>{{ \App\Support\Locale\Display::money($value, $currency) }}</bdi>

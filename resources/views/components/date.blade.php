{{-- Always YYYY-MM-DD (digits follow the user's language). --}}
@props(['value', 'time' => false])
<time {{ $attributes }} datetime="{{ $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : '' }}" dir="ltr">{{ $time ? \App\Support\Locale\Display::dateTime($value) : \App\Support\Locale\Display::date($value) }}</time>

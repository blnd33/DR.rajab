{{-- Number in the user's digit system (١٢٣ for Arabic/Sorani unless changed in Settings). --}}
@props(['value', 'decimals' => 0])
<span {{ $attributes }}>{{ \App\Support\Locale\Display::number($value, $decimals) }}</span>

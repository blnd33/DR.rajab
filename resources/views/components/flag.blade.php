{{--
    Flag shown next to a language (config('locales.supported.*.flag')).
    Inline SVG: flag emoji do not show on Windows and there is no emoji for the Kurdistan flag.
    The language name is always written next to it, so the flag itself is decorative.
    Shown as a circle: the middle square of the 30×20 flag (viewBox 5 0 20 20), rounded.
--}}
@props(['locale'])

@php
    $flag = config("locales.supported.{$locale}.flag");
@endphp

@if ($flag)
    <svg viewBox="5 0 20 20" aria-hidden="true" focusable="false"
         {{ $attributes->class(['inline-block size-5 shrink-0 overflow-hidden rounded-full shadow-[0_0_0_1px_rgb(0_0_0/0.12)]']) }}>
        @switch($flag)
            @case('us')
                <rect width="30" height="20" fill="#fff"/>
                <path fill="#B22234" d="M0 0h30v1.54H0zM0 3.08h30v1.54H0zM0 6.15h30v1.54H0zM0 9.23h30v1.54H0zM0 12.31h30v1.54H0zM0 15.38h30v1.54H0zM0 18.46h30V20H0z"/>
                <rect width="12" height="10.77" fill="#3C3B6E"/>
                <g fill="#fff">
                    @foreach ([1.5, 4.5, 7.5, 10.5] as $x)
                        @foreach ([1.6, 4.2, 6.8, 9.3] as $y)
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="0.55"/>
                        @endforeach
                    @endforeach
                    @foreach ([3, 6, 9] as $x)
                        @foreach ([2.9, 5.5, 8] as $y)
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="0.55"/>
                        @endforeach
                    @endforeach
                </g>
                @break
            @case('iq')
                <rect width="30" height="6.67" fill="#CE1126"/>
                <rect y="6.67" width="30" height="6.67" fill="#fff"/>
                <rect y="13.33" width="30" height="6.67" fill="#000"/>
                <text x="15" y="11.6" text-anchor="middle" direction="rtl" fill="#007A3D"
                      font-family="'Vazirmatn Variable', 'Noto Sans Arabic', Tahoma, sans-serif"
                      font-size="4.4" font-weight="700">الله أكبر</text>
                @break
            @case('krd')
                <rect width="30" height="6.67" fill="#ED2024"/>
                <rect y="6.67" width="30" height="6.67" fill="#fff"/>
                <rect y="13.33" width="30" height="6.67" fill="#278E43"/>
                <polygon fill="#FEBD11" points="15.00,6.75 15.32,7.87 15.96,6.89 15.93,8.06 16.83,7.31 16.46,8.42 17.54,7.97 16.86,8.93 18.03,8.81 17.10,9.52 18.24,9.76 17.14,10.16 18.17,10.72 17.00,10.79 17.81,11.62 16.68,11.34 17.21,12.38 16.21,11.78 16.41,12.93 15.63,12.05 15.48,13.21 15.00,12.15 14.52,13.21 14.37,12.05 13.59,12.93 13.79,11.78 12.79,12.38 13.32,11.34 12.19,11.63 13.00,10.79 11.83,10.72 12.86,10.16 11.76,9.76 12.90,9.52 11.97,8.81 13.14,8.93 12.46,7.97 13.54,8.42 13.17,7.31 14.07,8.06 14.04,6.89 14.68,7.87"/>
                <circle cx="15" cy="10" r="1.75" fill="#FEBD11"/>
                @break
        @endswitch
    </svg>
@endif

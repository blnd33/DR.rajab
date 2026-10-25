{{-- PWA meta shared by both panels. The service worker is registered by the page scripts. --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="#0E7490">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ app(\App\Services\Settings\SettingsRepository::class)->get('company.name', config('app.name')) }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="ot-sw" content="{{ route('pwa.sw') }}">

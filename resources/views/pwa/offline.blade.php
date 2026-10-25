{{-- Cached by the service worker; shown when a page is opened without a connection. Self-contained (no external CSS). --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Support\Locale\LocaleRegistry::class)->direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('Offline') }}</title>
    <style>
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; background: #f8fafc; color: #0f172a;
               font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; }
        main { max-width: 22rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { font-size: 1.375rem; margin: 1rem 0 .5rem; }
        p { color: #475569; line-height: 1.6; margin: 0 0 .5rem; }
        button { margin-top: 1.25rem; min-height: 3rem; padding: 0 1.5rem; border: 0; border-radius: .75rem;
                 background: #0e7490; color: #fff; font-size: 1rem; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <img src="/icons/favicon.svg" alt="" width="56" height="56">
    {{-- All three languages: the cached page may be shown to anyone. --}}
    <h1>{{ __('You are offline') }}</h1>
    <p lang="en" dir="ltr">No internet connection. Drafts you started are saved on this phone and will sync when you are back online.</p>
    <p lang="ar" dir="rtl">لا يوجد اتصال بالإنترنت. المسودات محفوظة على هذا الهاتف وستتم مزامنتها عند عودة الاتصال.</p>
    <p lang="ckb" dir="rtl">پەیوەندی ئینتەرنێت نییە. ڕەشنووسەکان لەسەر ئەم مۆبایلە پاشەکەوت کراون و کاتێک پەیوەندی گەڕایەوە هاوکات دەکرێن.</p>
    <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
</main>
</body>
</html>

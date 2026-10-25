<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Support\Locale\LocaleRegistry::class)->direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print labels') }} — {{ config('orthotrack.name') }}</title>
    @vite('resources/css/ops.css')
    @php
        $w = (float) $template->width_mm;
        $h = (float) $template->height_mm;
        $font = $template->fontPt();
        $twoD = $labels->first()?->twoDimensional ?? false;
    @endphp
    <style>
        @page { size: {{ $w }}mm {{ $h }}mm; margin: 0; }
        html, body { margin: 0; padding: 0; background: #e2e8f0; }
        .sheet { display: flex; flex-wrap: wrap; gap: 4mm; padding: 6mm; justify-content: center; }
        .label {
            box-sizing: border-box; width: {{ $w }}mm; height: {{ $h }}mm; padding: 1.5mm;
            background: #fff; color: #000; overflow: hidden;
            display: flex; gap: 1.5mm; align-items: stretch;
            flex-direction: {{ $twoD ? 'row' : 'column' }};
            font-size: {{ $font }}pt; line-height: 1.15;
            break-after: page; page-break-after: always;
        }
        .label .code { flex: {{ $twoD ? '0 0 auto' : '1 1 auto' }}; {{ $twoD ? 'height: 100%; aspect-ratio: 1;' : 'min-height: 40%;' }} }
        .ot-label-barcode { width: 100%; height: 100%; display: block; }
        .label .text { min-width: 0; flex: 1 1 auto; display: flex; flex-direction: column; justify-content: center; }
        .label .line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .label .line-name { font-weight: 700; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .label .mono { font-family: var(--font-mono), ui-monospace, monospace; direction: ltr; unicode-bidi: isolate; text-align: start; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; align-items: center; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #cbd5e1; font-family: var(--font-sans); }
        @media print {
            html, body { background: #fff; }
            .toolbar { display: none; }
            .sheet { display: block; padding: 0; }
            .label { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ trans_choice(':count label|:count labels', $labels->count(), ['count' => \App\Support\Locale\Display::number($labels->count())]) }}</strong>
        <span>· {{ $template->name }} ({{ $template->sizeLabel() }})</span>
        <button type="button" class="ot-btn ot-btn-primary" onclick="window.print()">{{ __('Print') }}</button>
        <button type="button" class="ot-btn ot-btn-secondary" onclick="window.close()">{{ __('Close') }}</button>
    </div>

    <main class="sheet">
        @foreach ($labels as $label)
            <section class="label" aria-label="{{ $label->payload }}">
                <div class="code">{!! $label->svg !!}</div>
                <div class="text">
                    @foreach ($label->lines as $field => $text)
                        <div @class(['line', 'line-name' => $field === 'name', 'mono' => $field !== 'name'])>{{ $text }}</div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </main>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300))
    </script>
</body>
</html>

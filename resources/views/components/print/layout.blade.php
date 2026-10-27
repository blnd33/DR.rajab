{{--
    A4 print layout for documents and reports (P5-3): company header, title, meta lines, body, footer.
    Same page for the browser's print dialog and for the PDF download (Browsershot).
--}}
@props(['title', 'meta' => [], 'pdfUrl' => null, 'landscape' => false])
@php
    $settings = app(\App\Services\Settings\SettingsRepository::class);
    $company = (string) ($settings->get('company.legal_name') ?: $settings->get('company.name') ?: config('orthotrack.name'));
    $logoPath = $settings->get('company.logo_path');
    $logo = null;
    if (filled($logoPath) && \Illuminate\Support\Facades\Storage::disk('public')->exists($logoPath)) {
        $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($logoPath) ?: 'image/png';
        $logo = 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('public')->get($logoPath));
    }
    $printedBy = auth()->user()?->name;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Support\Locale\LocaleRegistry::class)->direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $company }}</title>
    @vite('resources/css/ops.css')
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 14mm 12mm; }
        html, body { background: #e2e8f0; color: #0f172a; margin: 0; }
        .doc { box-sizing: border-box; max-width: {{ $landscape ? '297mm' : '210mm' }}; margin: 0 auto 24px; padding: 14mm 12mm; background: #fff; font-size: 10pt; line-height: 1.45; }
        .doc header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
        .doc header img { max-height: 48px; max-width: 160px; }
        .doc h1 { font-size: 16pt; margin: 0 0 4px; }
        .doc .company { font-weight: 700; font-size: 12pt; }
        .doc .muted { color: #475569; }
        .doc .meta { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 4px 16px; margin: 8px 0 14px; }
        .doc .meta dt { color: #475569; font-size: 8.5pt; }
        .doc .meta dd { margin: 0; font-weight: 600; }
        .doc table { width: 100%; border-collapse: collapse; }
        .doc th, .doc td { border-bottom: 1px solid #e2e8f0; padding: 4px 6px; text-align: start; vertical-align: top; }
        .doc th { background: #f1f5f9; font-size: 8.5pt; text-transform: none; }
        .doc td.num, .doc th.num { text-align: end; white-space: nowrap; }
        .doc tfoot td { font-weight: 700; border-top: 2px solid #0f172a; }
        .doc .code { font-family: var(--font-mono), ui-monospace, monospace; direction: ltr; unicode-bidi: isolate; }
        .doc .ltr { direction: ltr; unicode-bidi: isolate; }
        .doc section + section { margin-top: 14px; }
        .doc h2 { font-size: 11pt; margin: 0 0 6px; }
        .doc .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 36px; }
        .doc .signatures div { border-top: 1px solid #94a3b8; padding-top: 4px; color: #475569; }
        .doc footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 8pt; color: #64748b; display: flex; justify-content: space-between; gap: 12px; }
        .toolbar { position: sticky; top: 0; z-index: 1; display: flex; gap: 8px; align-items: center; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #cbd5e1; }
        tr { break-inside: avoid; }
        thead { display: table-header-group; }
        @media print {
            html, body { background: #fff; }
            .toolbar { display: none; }
            .doc { max-width: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    @unless (request()->boolean('pdf'))
        <div class="toolbar">
            <button type="button" class="ot-btn ot-btn-primary" onclick="window.print()">{{ __('Print') }}</button>
            @if ($pdfUrl)
                <a href="{{ $pdfUrl }}" class="ot-btn ot-btn-secondary">{{ __('Download PDF') }}</a>
            @endif
            <button type="button" class="ot-btn ot-btn-secondary" onclick="window.close()">{{ __('Close') }}</button>
        </div>
    @endunless

    <article class="doc">
        <header>
            <div>
                <div class="company">{{ $company }}</div>
                @if ($settings->get('company.address'))
                    <div class="muted">{{ $settings->get('company.address') }}</div>
                @endif
                @if ($settings->get('company.phone'))
                    <div class="muted ltr">{{ $settings->get('company.phone') }}</div>
                @endif
            </div>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $company }}">
            @endif
        </header>

        <h1>{{ $title }}</h1>
        @if ($meta !== [])
            <dl class="meta">
                @foreach ($meta as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif

        {{ $slot }}

        <footer>
            <span>{{ __('Printed :date by :user', ['date' => \App\Support\Locale\Display::dateTime(now()), 'user' => $printedBy ?? '—']) }}</span>
            <span>OrthoTrack</span>
        </footer>
    </article>
</body>
</html>

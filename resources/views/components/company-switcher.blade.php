{{--
    Company switch, next to the company name (top bar) and on the sign-in page: one button with the
    other company's name — "Al-Fadhaa' Bureau" in KurdishMedicus, KurdishMedicus' name in
    Al-Fadhaa' Bureau. Each company is its own system (data, staff, sign-in). Shown only when the
    other company has a database.
--}}
@props(['variant' => 'topbar'])

@php
    $current = \App\Support\CompanyContext::current();
    $others = array_values(array_filter(\App\Support\CompanyContext::available(), fn ($c) => $c !== $current));
@endphp

@if ($others !== [])
    <form method="POST" action="{{ route('admin.company.switch') }}" data-company-switch
          {{ $attributes->class(['flex items-center gap-2', 'ms-4' => $variant === 'topbar', 'mx-auto mb-2 w-fit' => $variant === 'login']) }}>
        @csrf
        @foreach ($others as $company)
            <button type="submit" name="company" value="{{ $company->value }}"
                    title="{{ __('Open :company', ['company' => \App\Support\CompanyContext::name($company)]) }}"
                    class="flex h-9 cursor-pointer items-center gap-1.5 rounded-lg border border-cyan-700/40 bg-cyan-50 px-3 text-sm font-semibold whitespace-nowrap text-cyan-800 hover:bg-cyan-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700 dark:border-cyan-400/30 dark:bg-cyan-400/10 dark:text-cyan-200 dark:hover:bg-cyan-400/20">
                @svg('heroicon-o-arrows-right-left', 'size-4 shrink-0')
                <span>{{ \App\Support\CompanyContext::name($company) }}</span>
            </button>
        @endforeach
    </form>
@endif

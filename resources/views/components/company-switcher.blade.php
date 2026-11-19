{{--
    The button next to the company name: the other company — "Al-Fadhaa' Bureau" in KurdishMedicus,
    KurdishMedicus' own name in Al-Fadhaa' Bureau. Only for people who may open the other company
    (companies.switch: KurdishMedicus' super admin); Al-Fadhaa' Bureau has no login of its own.
--}}
@php
    $current = \App\Support\CompanyContext::current();
    $others = array_values(array_filter(\App\Support\CompanyContext::available(), fn ($c) => $c !== $current));
    $user = auth()->user();
@endphp

@if ($others !== [] && $user?->can('companies.switch'))
    <form method="POST" action="{{ route('admin.company.switch') }}" data-company-switch
          {{ $attributes->class(['ms-4 flex items-center gap-2']) }}>
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

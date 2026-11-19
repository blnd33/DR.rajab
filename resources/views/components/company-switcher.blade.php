{{--
    Company switch (top bar and sign-in page): KurdishMedicus | Al-Fadhaa' Bureau. Each company is its
    own system (data, staff, sign-in); shown only when more than one company has a database.
--}}
@php
    $companies = \App\Support\CompanyContext::available();
    $current = \App\Support\CompanyContext::current();
@endphp

@if (count($companies) > 1)
    <form method="POST" action="{{ route('admin.company.switch') }}" data-company-switch
          {{ $attributes->class(['flex h-10 items-center overflow-hidden rounded-lg border border-slate-300 bg-white text-sm font-semibold dark:border-white/15 dark:bg-white/5']) }}>
        @csrf
        @foreach ($companies as $company)
            <button type="submit" name="company" value="{{ $company->value }}"
                    aria-pressed="{{ $company === $current ? 'true' : 'false' }}"
                    @class([
                        'flex h-full cursor-pointer items-center gap-1.5 px-3 whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-cyan-700',
                        'border-s border-slate-300 dark:border-white/15' => ! $loop->first,
                        'bg-cyan-700 text-white' => $company === $current,
                        'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5' => $company !== $current,
                    ])>
                @svg('heroicon-o-briefcase', 'size-4 shrink-0')
                <span>{{ $company->getLabel() }}</span>
            </button>
        @endforeach
    </form>
@endif

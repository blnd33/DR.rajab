{{-- Admin top bar: work in one branch (everything branch-based follows) or in all of them. --}}
@php
    $user = auth()->user();
    $branches = $user ? \App\Support\AdminBranch::options($user) : collect();
    $current = \App\Support\AdminBranch::id();
@endphp

@if ($branches->count() > 1)
    <form method="POST" action="{{ route('admin.branch.switch') }}" {{ $attributes->class(['relative flex items-center']) }}>
        @csrf
        <label for="admin-branch-switch" class="sr-only">{{ __('Station') }}</label>
        <span class="pointer-events-none absolute start-2 flex items-center text-slate-500" aria-hidden="true">
            @svg('heroicon-o-building-office-2', 'size-5')
        </span>
        <select id="admin-branch-switch" name="branch_id" data-branch-switch onchange="this.form.submit()"
                class="h-10 cursor-pointer appearance-none rounded-lg border border-slate-300 bg-white py-0 ps-9 pe-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700 dark:border-white/15 dark:bg-white/5 dark:text-slate-200">
            <option value="all" @selected($current === null)>{{ __('All stations') }}</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected($current === $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>
            @endforeach
        </select>
    </form>
@endif

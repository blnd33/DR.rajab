@props(['user'])

@php
    use App\Services\Permissions\BranchScopeResolver;
    use App\Support\CurrentBranch;

    $branches = $user ? app(BranchScopeResolver::class)->selectableBranches($user, 'stock.view')->get() : collect();
    $currentId = CurrentBranch::id();
    $unread = $user?->unreadNotifications()->count() ?? 0;
    $companyName = app(\App\Services\Settings\SettingsRepository::class)->get('company.name', config('app.name'));
@endphp

<header class="sticky top-0 z-30 border-b border-border bg-card/95 backdrop-blur ot-safe-top">
    <div class="mx-auto flex h-14 max-w-screen-md items-center gap-3 px-4">
        <a href="{{ route('ops.home') }}" wire:navigate class="flex min-w-0 items-center gap-2 font-semibold">
            <img src="{{ asset('icons/favicon.svg') }}" alt="" class="size-7 shrink-0" width="28" height="28">
            <span class="truncate">{{ $companyName }}</span>
        </a>

        <div class="ms-auto flex items-center gap-1">
            <x-language-switcher variant="compact" />

            @if ($branches->count() > 1)
                <form method="POST" action="{{ route('ops.branch.switch') }}" x-data>
                    @csrf
                    <label for="branch-switch" class="sr-only">{{ __('Branch') }}</label>
                    <select id="branch-switch" name="branch_id" @change="$el.form.submit()"
                            class="h-10 rounded-lg border border-border-strong bg-card px-2 text-sm font-semibold">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branch->id === $currentId)>{{ $branch->code }}</option>
                        @endforeach
                    </select>
                </form>
            @elseif ($branches->count() === 1)
                <span class="rounded-lg bg-subtle px-2 py-1 text-sm font-semibold" title="{{ $branches->first()->name }}">
                    {{ $branches->first()->code }}
                </span>
            @endif

            <a href="{{ route('ops.notifications') }}" wire:navigate
               class="relative inline-flex size-11 items-center justify-center rounded-full hover:bg-subtle"
               aria-label="{{ trans_choice('Notifications (:count unread)|Notifications (:count unread)', $unread, ['count' => $unread]) }}">
                @svg('heroicon-o-bell', 'size-6')
                @if ($unread > 0)
                    <span class="absolute end-1.5 top-1.5 flex min-w-5 items-center justify-center rounded-full bg-danger px-1 text-xs font-bold text-white">
                        {{ \App\Support\Locale\Display::number(min($unread, 99)) }}
                    </span>
                @endif
            </a>
        </div>
    </div>
</header>

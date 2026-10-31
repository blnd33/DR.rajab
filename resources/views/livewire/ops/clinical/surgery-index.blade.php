<div class="space-y-6">
    <header class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('My surgeries') }}</h1>
        </div>
        @if ($canCreate)
            <a href="{{ route('ops.surgeries.create') }}" class="ot-btn ot-btn-primary shrink-0">
                @svg('heroicon-o-plus', 'size-5')
                <span>{{ __('New surgery') }}</span>
            </a>
        @endif
    </header>

    {{-- Only on this phone (not sent yet, or waiting for a connection) --}}
    <section x-data="localDrafts({ resumeUrl: @js(route('ops.surgeries.create')) })" x-show="drafts.length > 0" x-cloak class="space-y-3" aria-labelledby="local-title">
        <div class="flex items-center justify-between gap-2">
            <h2 id="local-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Saved on this phone') }}</h2>
            <button type="button" class="ot-btn ot-btn-secondary min-h-9 px-3 text-sm" @click="sendNow()">{{ __('Send now') }}</button>
        </div>
        <template x-for="draft in drafts" :key="draft.client_uuid">
            <button type="button" class="ot-card flex w-full items-center justify-between gap-3 p-4 text-start hover:bg-subtle" @click="resume(draft)">
                <span class="min-w-0">
                    <span class="block font-semibold" x-text="draft.title || @js(__('Surgery draft'))"></span>
                    <span class="block text-sm text-muted-fg" x-text="draft.status === 'pending' ? @js(__('Waiting to be sent')) : @js(__('Not sent yet'))"></span>
                </span>
                @svg('heroicon-o-chevron-right', 'size-5 shrink-0 text-muted-fg rtl:rotate-180')
            </button>
        </template>
    </section>

    @if ($drafts->isNotEmpty())
        <section class="space-y-3" aria-labelledby="drafts-title">
            <h2 id="drafts-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Drafts to finish') }}</h2>
            @foreach ($drafts as $surgery)
                @include('livewire.ops.clinical.partials.surgery-card', ['surgery' => $surgery])
            @endforeach
        </section>
    @endif

    <section class="space-y-3" aria-labelledby="recent-title">
        <h2 id="recent-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Recent surgeries') }}</h2>
        @forelse ($recent as $surgery)
            @include('livewire.ops.clinical.partials.surgery-card', ['surgery' => $surgery])
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-heart" :title="__('No surgeries yet')" /></div>
        @endforelse
    </section>
</div>

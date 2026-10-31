<div x-data="countQueue({ countId: {{ $count->id }}, endpoint: @js(route('ops.sync.count-scans')) })"
     @scanned.window="onScan($event.detail.raw)" class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Stock count') }}</p>
        <h1 class="text-2xl font-bold"><x-code :value="$count->number" /></h1>
        <p class="flex flex-wrap items-center gap-2 text-muted-fg">
            <span>{{ $count->scopeLabel() }}</span>
            <x-ops.status-chip :label="$count->status->getLabel()" :color="$count->status->getColor()" :icon="$count->status->getIcon()" />
        </p>
    </header>

    <section class="grid grid-cols-2 gap-3" aria-label="{{ __('Progress') }}">
        <div class="ot-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Serial units found') }}</p>
            <p class="mt-1 text-2xl font-bold"><x-num :value="$unitsFound" /> / <x-num :value="$unitsExpected" /></p>
        </div>
        <div class="ot-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Extra items') }}</p>
            <p @class(['mt-1 text-2xl font-bold', 'text-warning' => $extras > 0])><x-num :value="$extras" /></p>
        </div>
    </section>

    @if ($canScan)
        <section x-data="scanner({ serverFeedback: true })" class="ot-card space-y-3 p-4" aria-label="{{ __('Scan') }}">
            <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-fg" wire:ignore x-show="cameraOn" x-cloak>
                <video x-ref="video" class="h-full w-full object-cover" playsinline muted aria-label="{{ __('Camera preview') }}"></video>
                <div class="pointer-events-none absolute inset-6 rounded-xl border-4 border-white/80"></div>
            </div>
            <button type="button" class="ot-btn w-full" :class="cameraOn ? 'ot-btn-secondary' : 'ot-btn-primary'" @click="toggleCamera()" :disabled="starting">
                @svg('heroicon-o-camera', 'size-5')
                <span x-show="!cameraOn">{{ __('Scan with camera') }}</span>
                <span x-show="cameraOn" x-cloak>{{ __('Stop camera') }}</span>
            </button>
            <form @submit.prevent="submitManual()" class="flex gap-2" wire:ignore>
                <label for="count-code" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
                <input id="count-code" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="{{ __('Scan or type a code') }}">
                <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
            </form>
            <p class="text-sm text-muted-fg" role="status">
                <span x-show="queued > 0" x-cloak class="font-semibold text-info" x-text="@js(__('Waiting to sync:')) + ' ' + queued"></span>
            </p>
        </section>
    @endif

    <div class="flex gap-2" role="tablist">
        <button type="button" wire:click="$set('filter', 'open')" role="tab" aria-selected="{{ $filter === 'open' ? 'true' : 'false' }}"
                @class(['ot-btn flex-1', 'ot-btn-primary' => $filter === 'open', 'ot-btn-secondary' => $filter !== 'open'])>{{ __('Still to count') }}</button>
        <button type="button" wire:click="$set('filter', 'all')" role="tab" aria-selected="{{ $filter === 'all' ? 'true' : 'false' }}"
                @class(['ot-btn flex-1', 'ot-btn-primary' => $filter === 'all', 'ot-btn-secondary' => $filter !== 'all'])>{{ __('Everything') }}</button>
    </div>

    <section class="space-y-3">
        @forelse ($lines as $line)
            <article wire:key="cl-{{ $line->id }}" @class([
                'ot-card space-y-2 p-4',
                'border-s-4 border-s-success' => $line->stock_unit_id !== null && $line->counted_qty === $line->expected_qty && $line->expected_qty > 0,
                'border-s-4 border-s-warning' => in_array($line->result, [\App\Enums\CountLineResult::ExtraOtherBranch, \App\Enums\CountLineResult::ExtraUnknown], true),
            ])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-semibold">{{ $line->product->name }}@if ($line->product->size) · <bdi class="whitespace-nowrap">{{ $line->product->size }}</bdi> @endif</p>
                        <p class="text-sm"><x-code :value="$line->product->sku" class="text-muted-fg" />
                            @if ($line->stock_unit_id || $line->stock_batch_id) · <x-code :value="$line->code()" class="font-semibold" /> @endif
                        </p>
                        @if ($line->result && $line->result !== \App\Enums\CountLineResult::Found)
                            <x-ops.status-chip :label="$line->result->getLabel().($line->foundBranch ? ' · '.$line->foundBranch->code : '')" :color="$line->result->getColor()" :icon="$line->result->getIcon()" />
                        @endif
                    </div>
                    @if ($line->stock_unit_id !== null)
                        <div class="shrink-0">
                            @if ($line->counted_qty >= 1)
                                <x-ops.status-chip :label="__('Found')" color="success" icon="heroicon-o-check-circle" />
                            @else
                                <x-ops.status-chip :label="__('Not scanned yet')" icon="heroicon-o-clock" />
                            @endif
                        </div>
                    @endif
                </div>

                @if ($line->stock_batch_id !== null)
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-muted-fg">{{ __('Expected :qty', ['qty' => \App\Support\Locale\Display::number($line->expected_qty)]) }}</span>
                        @if ($canScan)
                            <div class="flex items-center gap-2">
                                <label for="lc-{{ $line->id }}" class="sr-only">{{ __('Counted') }}</label>
                                <input id="lc-{{ $line->id }}" type="number" min="0" inputmode="numeric" wire:model="lotCounts.{{ $line->id }}" wire:change="saveLotCount({{ $line->id }})" class="ot-input w-24 text-center" dir="ltr">
                            </div>
                        @else
                            <span class="font-bold">{{ __('Counted :qty', ['qty' => \App\Support\Locale\Display::number($line->counted_qty)]) }}</span>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-check-circle" :title="__('Everything is counted')" /></div>
        @endforelse
    </section>

    @if ($canScan)
        <button type="button" wire:click="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg"
                wire:confirm="{{ __('Submit the count? Items not scanned will be reported as missing.') }}">
            @svg('heroicon-o-check', 'size-6')
            <span>{{ __('Submit count') }}</span>
        </button>
    @endif
</div>

<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Receive stock') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('Scan each box into :branch, check the list, then confirm.', ['branch' => $branch?->code]) }}</p>
    </header>

    {{-- Scan: camera or hardware scanner --}}
    <section class="ot-card space-y-3 p-4" aria-label="{{ __('Scan') }}">
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
            <label for="receive-code" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
            <input id="receive-code" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr"
                   autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="{{ __('Scan or type a code') }}">
            <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
        </form>
    </section>

    {{-- Add by hand --}}
    <section class="ot-card space-y-2 p-4">
        <label for="product-search" class="block text-sm font-semibold">{{ __('No barcode? Find the product') }}</label>
        <input id="product-search" type="search" wire:model.live.debounce.300ms="productSearch" class="ot-input" autocomplete="off" placeholder="{{ __('SKU or name') }}">
        @if ($matches->isNotEmpty())
            <ul class="divide-y divide-border rounded-lg border border-border">
                @foreach ($matches as $match)
                    <li>
                        <button type="button" wire:click="addProduct({{ $match->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-subtle">
                            <span class="min-w-0"><x-code :value="$match->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $match->name }}@if ($match->size) · <bdi>{{ $match->size }}</bdi> @endif</span></span>
                            @svg('heroicon-o-plus-circle', 'size-6 shrink-0 text-primary')
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- The list --}}
    <section class="space-y-3" aria-labelledby="lines-title">
        <h2 id="lines-title" class="flex items-center justify-between text-sm font-semibold uppercase tracking-wide text-muted-fg">
            <span>{{ __('To receive') }}</span>
            <span class="rounded-full bg-subtle px-2 py-0.5 text-fg"><x-num :value="$total" /></span>
        </h2>

        @forelse ($lines as $key => $line)
            <article wire:key="line-{{ $key }}" class="ot-card space-y-3 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $line['name'] }}@if ($line['size']) · <bdi>{{ $line['size'] }}</bdi> @endif</p>
                        <x-code :value="$line['sku']" class="text-sm text-muted-fg" />
                    </div>
                    <button type="button" wire:click="removeLine('{{ $key }}')" class="-m-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-fg hover:bg-subtle" aria-label="{{ __('Remove') }}">
                        @svg('heroicon-o-trash', 'size-5')
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @if ($line['serial_tracked'])
                        <div class="col-span-2">
                            <label for="serial-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">{{ __('Serial') }} *</label>
                            <input id="serial-{{ $key }}" wire:model.blur="lines.{{ $key }}.serial" data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" required>
                        </div>
                    @endif
                    <div>
                        <label for="lot-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">{{ __('Lot') }}</label>
                        <input id="lot-{{ $key }}" wire:model.blur="lines.{{ $key }}.lot" data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off">
                    </div>
                    <div>
                        <label for="exp-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">{{ __('Expiry') }}{{ $line['requires_expiry'] ? ' *' : '' }}</label>
                        <input id="exp-{{ $key }}" type="date" wire:model.blur="lines.{{ $key }}.expiry_date" class="ot-input" dir="ltr">
                    </div>
                    @unless ($line['serial_tracked'])
                        <div class="col-span-2 flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold">{{ __('Quantity') }}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="increment('{{ $key }}', -1)" class="ot-btn ot-btn-secondary w-12 px-0" aria-label="{{ __('Less') }}">@svg('heroicon-o-minus', 'size-5')</button>
                                <input type="number" min="1" inputmode="numeric" wire:model.blur="lines.{{ $key }}.quantity" class="ot-input w-20 text-center" aria-label="{{ __('Quantity') }}" dir="ltr">
                                <button type="button" wire:click="increment('{{ $key }}')" class="ot-btn ot-btn-secondary w-12 px-0" aria-label="{{ __('More') }}">@svg('heroicon-o-plus', 'size-5')</button>
                            </div>
                        </div>
                    @endunless
                </div>

                @if ($line['expiry_date'])
                    <x-expiry-chip :date="$line['expiry_date']" />
                @endif
            </article>
        @empty
            <div class="ot-card">
                <x-ops.empty-state icon="heroicon-o-inbox-arrow-down" :title="__('The list is empty')" :description="__('Scan a box label to add it.')" />
            </div>
        @endforelse
    </section>

    @if ($lines)
        <div class="ot-safe-bottom sticky bottom-20 z-20">
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="ot-btn ot-btn-primary w-full py-4 text-lg shadow-lg">
                <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                    @svg('heroicon-o-check', 'size-6')
                    {{ trans_choice('Add :count item to stock|Add :count items to stock', $total, ['count' => \App\Support\Locale\Display::number($total)]) }}
                </span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    @endif
</div>

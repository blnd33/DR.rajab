<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Receive stock') }}</h1>
    </header>

    {{-- Scan: camera or hardware scanner --}}
    <section class="ot-card space-y-3 p-4" aria-label="{{ __('Scan') }}">
        <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-fg" wire:ignore x-show="cameraOn" x-cloak>
            <video x-ref="video" class="h-full w-full object-cover" playsinline muted aria-label="{{ __('Camera preview') }}"></video>
            <div class="pointer-events-none absolute inset-x-6 top-1/2 h-1/2 -translate-y-1/2 rounded-xl border-4 border-white/80">
                <div class="absolute inset-x-3 top-1/2 h-0.5 -translate-y-1/2 bg-danger"></div>
            </div>
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

    {{-- A box barcode the system doesn't know yet: link it once --}}
    @if ($unknown)
        <section class="ot-card space-y-3 border-s-4 border-s-warning p-4" role="alert" aria-labelledby="link-title">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning-soft text-warning">@svg('ot-barcode', 'size-6')</span>
                <div class="min-w-0">
                    <h2 id="link-title" class="font-semibold">{{ __('New barcode — which product is this?') }}</h2>
                    <x-code :value="$unknown['gtin']" class="text-sm text-muted-fg" />
                    @if ($unknown['prefill']['lot'] || $unknown['prefill']['expiry_date'])
                        <p class="text-sm text-muted-fg">
                            @if ($unknown['prefill']['lot']) {{ __('Lot') }} <x-code :value="$unknown['prefill']['lot']" /> @endif
                            @if ($unknown['prefill']['expiry_date']) · {{ __('Expiry') }} <x-date :value="$unknown['prefill']['expiry_date']" /> @endif
                        </p>
                    @endif
                </div>
            </div>
            <label for="link-search" class="block text-sm font-semibold">{{ __('Search by REF (from the box) or name') }}</label>
            <input id="link-search" type="search" wire:model.live.debounce.300ms="linkSearch" class="ot-input ot-code" dir="ltr" autocomplete="off" placeholder="4250.20.100">
            @if ($linkMatches->isNotEmpty())
                <ul class="divide-y divide-border rounded-lg border border-border">
                    @foreach ($linkMatches as $match)
                        <li>
                            <button type="button" wire:click="linkProduct({{ $match->id }})" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-subtle">
                                <span class="min-w-0"><x-code :value="$match->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $match->name }}@if ($match->size) · <bdi class="whitespace-nowrap">{{ $match->size }}</bdi> @endif</span></span>
                                @svg('heroicon-o-link', 'size-6 shrink-0 text-primary')
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($linkSearch)) >= 2)
                <p class="text-sm text-muted-fg">{{ __('No product found.') }}</p>
            @endif
            <button type="button" wire:click="cancelLink" class="ot-btn ot-btn-secondary w-full">{{ __('Cancel') }}</button>
        </section>
    @endif

    {{-- Add by hand --}}
    <section class="ot-card space-y-2 p-4">
        <label for="product-search" class="block text-sm font-semibold">{{ __('No barcode? Find the product') }}</label>
        <input id="product-search" type="search" wire:model.live.debounce.300ms="productSearch" class="ot-input" autocomplete="off" placeholder="{{ __('SKU or name') }}">
        @if ($matches->isNotEmpty())
            <ul class="divide-y divide-border rounded-lg border border-border">
                @foreach ($matches as $match)
                    <li>
                        <button type="button" wire:click="addProduct({{ $match->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-subtle">
                            <span class="min-w-0"><x-code :value="$match->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $match->name }}@if ($match->size) · <bdi class="whitespace-nowrap">{{ $match->size }}</bdi> @endif</span></span>
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
                        <p class="font-semibold">{{ $line['name'] }}@if ($line['size']) · <bdi class="whitespace-nowrap">{{ $line['size'] }}</bdi> @endif</p>
                        <x-code :value="$line['sku']" class="text-sm text-muted-fg" />
                    </div>
                    <button type="button" wire:click="removeLine('{{ $key }}')" class="-m-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-fg hover:bg-subtle" aria-label="{{ __('Remove') }}">
                        @svg('heroicon-o-trash', 'size-5')
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    {{-- Serial-tracked: SN required. Lot items: the box's SN or LOT, whichever the label shows. --}}
                    <div class="col-span-2">
                        <label for="serial-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">
                            {{ __('Serial') }} (SN){{ $line['serial_tracked'] ? ' *' : '' }}
                            @unless ($line['serial_tracked']) <span class="font-normal">· {{ __('optional') }}</span> @endunless
                        </label>
                        <input id="serial-{{ $key }}" wire:model.blur="lines.{{ $key }}.serial" data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" @required($line['serial_tracked'])>
                    </div>
                    <div>
                        <label for="lot-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">{{ __('Lot') }} (LOT) <span class="font-normal">· {{ __('optional') }}</span></label>
                        <input id="lot-{{ $key }}" wire:model.blur="lines.{{ $key }}.lot" data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off">
                    </div>
                    <div>
                        <label for="exp-{{ $key }}" class="mb-1 block text-xs font-semibold text-muted-fg">{{ __('Expiry') }}{{ $line['requires_expiry'] ? ' *' : '' }}</label>
                        <x-ops.date-input id="exp-{{ $key }}" wire:model.blur="lines.{{ $key }}.expiry_date" />
                    </div>
                    @unless ($line['serial_tracked'] || filled($line['serial']))
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

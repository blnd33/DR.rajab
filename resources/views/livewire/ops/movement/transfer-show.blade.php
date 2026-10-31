<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Transfer') }}</p>
        <h1 class="text-2xl font-bold"><x-code :value="$transfer->number" /></h1>
        <p class="flex flex-wrap items-center gap-2 text-muted-fg">
            <span class="font-semibold text-fg">{{ $transfer->fromBranch->code }}</span>
            @svg('heroicon-o-arrow-right', 'size-4 rtl:rotate-180')
            <span class="font-semibold text-fg">{{ $transfer->toBranch->code }}</span>
            <x-ops.status-chip :label="$transfer->status->getLabel()" :color="$transfer->status->getColor()" :icon="$transfer->status->getIcon()" />
        </p>
        @if ($transfer->notes)
            <p class="text-sm text-muted-fg">{{ $transfer->notes }}</p>
        @endif
    </header>

    {{-- What a stock request asked for --}}
    @if ($requested)
        <section class="ot-card space-y-2 p-4" aria-labelledby="requested-title">
            <h2 id="requested-title" class="text-sm font-semibold">{{ __('Requested by :branch', ['branch' => $transfer->toBranch->code]) }}</h2>
            <ul class="space-y-1 text-sm">
                @foreach ($requested as $line)
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0"><x-code :value="$line['sku']" /> <span class="text-muted-fg">{{ $line['name'] }}</span></span>
                        <span @class(['font-bold', 'text-success' => $line['on_slip'] >= $line['approved'], 'text-warning' => $line['on_slip'] < $line['approved']])>
                            <x-num :value="$line['on_slip']" /> / <x-num :value="$line['approved']" />
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Scanner (preparing or receiving) --}}
    @if ($canEdit || $canReceive)
        <section class="ot-card space-y-3 p-4" aria-label="{{ __('Scan') }}">
            <p class="text-sm font-semibold">
                {{ $canEdit ? __('Scan each item') : __('Scan what arrived') }}
            </p>
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
                <label for="transfer-code" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
                <input id="transfer-code" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="{{ __('Scan or type a code') }}">
                <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
            </form>
            @if ($canReceive)
                <p role="status" class="text-sm font-semibold">
                    {{ __(':done of :total units scanned', ['done' => \App\Support\Locale\Display::number($unitsScanned), 'total' => \App\Support\Locale\Display::number($unitsTotal)]) }}
                </p>
            @endif
        </section>
    @endif

    {{-- Lines --}}
    <section class="space-y-3" aria-labelledby="lines-title">
        <h2 id="lines-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Items') }}</h2>

        @forelse ($transfer->items as $item)
            @php($isUnit = $item->stock_unit_id !== null)
            @php($stockItem = $isUnit ? $item->stockUnit : $item->fromBatch)
            <article wire:key="i-{{ $item->id }}" @class([
                'ot-card space-y-2 p-4',
                'border-s-4 border-s-success' => $canReceive && $isUnit && in_array($item->stock_unit_id, $scanned, true),
            ])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-semibold">{{ $item->product->name }}@if ($item->product->size) · <bdi class="whitespace-nowrap">{{ $item->product->size }}</bdi> @endif</p>
                        <p class="text-sm">
                            <x-code :value="$item->product->sku" class="text-muted-fg" />
                            · {{ $isUnit ? __('Serial') : __('Lot') }} <x-code :value="$item->code()" class="font-semibold" />
                        </p>
                        @if ($stockItem?->expiry_date)
                            <x-expiry-chip :date="$stockItem->expiry_date" compact />
                        @endif
                    </div>
                    <div class="shrink-0 text-end">
                        @if ($transfer->status !== \App\Enums\TransferStatus::Draft && $transfer->status !== \App\Enums\TransferStatus::InTransit)
                            <x-ops.status-chip :label="$item->line_status->getLabel()" :color="$item->line_status->getColor()" :icon="$item->line_status->getIcon()" />
                        @elseif ($canReceive && $isUnit)
                            @if (in_array($item->stock_unit_id, $scanned, true))
                                <x-ops.status-chip :label="__('Scanned')" color="success" icon="heroicon-o-check-circle" />
                            @else
                                <x-ops.status-chip :label="__('Not scanned yet')" icon="heroicon-o-clock" />
                            @endif
                        @else
                            <p class="text-2xl font-bold"><x-num :value="$item->quantity_sent" /></p>
                        @endif
                    </div>
                </div>

                @if ($canEdit)
                    <div class="flex items-center justify-end gap-2">
                        @unless ($isUnit)
                            <button type="button" wire:click="changeQuantity({{ $item->id }}, -1)" class="ot-btn ot-btn-secondary w-12 px-0" aria-label="{{ __('Less') }}">@svg('heroicon-o-minus', 'size-5')</button>
                            <button type="button" wire:click="changeQuantity({{ $item->id }}, 1)" class="ot-btn ot-btn-secondary w-12 px-0" aria-label="{{ __('More') }}">@svg('heroicon-o-plus', 'size-5')</button>
                        @endunless
                        <button type="button" wire:click="removeItem({{ $item->id }})" class="ot-btn ot-btn-secondary w-12 px-0" aria-label="{{ __('Remove') }}">@svg('heroicon-o-trash', 'size-5')</button>
                    </div>
                @endif

                @if ($canReceive && ! $isUnit)
                    <div class="flex items-center justify-between gap-3">
                        <label for="qty-{{ $item->id }}" class="text-sm font-semibold">{{ __('Received (sent :sent)', ['sent' => \App\Support\Locale\Display::number($item->quantity_sent)]) }}</label>
                        <input id="qty-{{ $item->id }}" type="number" min="0" max="{{ $item->quantity_sent }}" inputmode="numeric" wire:model="lotQty.{{ $item->id }}" class="ot-input w-24 text-center" dir="ltr">
                    </div>
                @endif

                @if (! $canReceive && $item->quantity_received !== null && ! $isUnit && $item->quantity_received !== $item->quantity_sent)
                    <p class="text-sm text-warning">{{ __('Received :received of :sent', ['received' => \App\Support\Locale\Display::number($item->quantity_received), 'sent' => \App\Support\Locale\Display::number($item->quantity_sent)]) }}</p>
                @endif
            </article>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-qr-code" :title="__('No items yet')" :description="__('Scan the units and lot boxes to send.')" /></div>
        @endforelse

        @if ($canReceive && $extra)
            <div class="ot-card space-y-2 border-s-4 border-s-warning p-4">
                <p class="font-semibold">{{ __('Scanned but not on this transfer') }}</p>
                <ul class="space-y-1">
                    @foreach ($extra as $code)
                        <li class="flex items-center justify-between gap-2">
                            <x-code :value="$code" />
                            <button type="button" wire:click="forgetExtra(@js($code))" class="min-h-11 px-2 text-sm text-muted-fg underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- Actions --}}
    @if ($canEdit && $transfer->items->isNotEmpty())
        <section class="space-y-3">
            @if ($hasExpired && $canOverride)
                <div>
                    <label for="override" class="mb-1 block text-sm font-semibold text-danger">{{ __('Expired items are on this transfer — reason to send them anyway') }}</label>
                    <input id="override" wire:model="overrideReason" class="ot-input">
                </div>
            @elseif ($hasExpired)
                <p class="flex items-start gap-2 rounded-lg bg-danger-soft p-3 text-sm font-medium text-danger">
                    @svg('heroicon-o-no-symbol', 'mt-0.5 size-5 shrink-0')
                    <span>{{ __('Expired items cannot be transferred. Remove them, or ask an admin.') }}</span>
                </p>
            @endif
            <button type="button" wire:click="send" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg">
                @svg('heroicon-o-truck', 'size-6')
                <span>{{ __('Send to :branch', ['branch' => $transfer->toBranch->code]) }}</span>
            </button>
        </section>
    @endif

    @if ($canReceive)
        <button type="button" wire:click="receive" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg"
                wire:confirm="{{ $unitsScanned < $unitsTotal ? __('Some units were not scanned. They will be reported as missing. Receive anyway?') : __('Receive this transfer?') }}">
            @svg('heroicon-o-check', 'size-6')
            <span>{{ __('Receive') }}</span>
        </button>
    @endif

    @if ($canCancel)
        <details class="ot-card p-4">
            <summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-danger">{{ __('Cancel this transfer') }}</summary>
            <div class="mt-3 space-y-2">
                <label for="cancel-reason" class="block text-sm font-semibold">{{ __('Reason') }}</label>
                <input id="cancel-reason" wire:model="cancelReason" class="ot-input">
                <button type="button" wire:click="cancel" class="ot-btn ot-btn-danger w-full" wire:confirm="{{ __('Cancel this transfer? Items in transit go back to the sending branch.') }}">{{ __('Cancel transfer') }}</button>
            </div>
        </details>
    @endif
</div>

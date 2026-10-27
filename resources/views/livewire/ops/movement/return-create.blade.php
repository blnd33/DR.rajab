<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Customer return') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('Items coming back from a hospital or customer. Scan serial numbers; pick lot products below.') }}</p>
    </header>

    <section class="ot-card space-y-3 p-4" aria-label="{{ __('Scan') }}">
        <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-fg" wire:ignore x-show="cameraOn" x-cloak>
            <video x-ref="video" class="h-full w-full object-cover" playsinline muted aria-label="{{ __('Camera preview') }}"></video>
        </div>
        <button type="button" class="ot-btn w-full" :class="cameraOn ? 'ot-btn-secondary' : 'ot-btn-primary'" @click="toggleCamera()" :disabled="starting">
            @svg('heroicon-o-camera', 'size-5')
            <span x-show="!cameraOn">{{ __('Scan with camera') }}</span>
            <span x-show="cameraOn" x-cloak>{{ __('Stop camera') }}</span>
        </button>
        <form @submit.prevent="submitManual()" class="flex gap-2" wire:ignore>
            <label for="ret-code" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
            <input id="ret-code" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" placeholder="{{ __('Scan or type a code') }}">
            <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
        </form>
        <div>
            <label for="ret-search" class="mb-1 block text-sm font-semibold">{{ __('Lot product without a label') }}</label>
            <input id="ret-search" type="search" wire:model.live.debounce.300ms="search" class="ot-input" placeholder="{{ __('SKU or name') }}">
            @if ($matches->isNotEmpty())
                <ul class="mt-2 divide-y divide-border rounded-lg border border-border">
                    @foreach ($matches as $match)
                        <li><button type="button" wire:click="addLot({{ $match->id }})" class="flex min-h-12 w-full items-center justify-between gap-2 px-3 text-start hover:bg-subtle">
                            <span><x-code :value="$match->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $match->name }}</span></span>
                            @svg('heroicon-o-plus-circle', 'size-6 text-primary')
                        </button></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section class="space-y-3">
        @forelse ($items as $key => $item)
            <article wire:key="ri-{{ $key }}" class="ot-card space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $item['label'] }}</p>
                        <x-code :value="$item['code']" class="text-sm" />
                        @if (! empty($item['origin']))
                            <p class="text-xs text-muted-fg">{{ __('Was: :origin', ['origin' => $item['origin']]) }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="remove('{{ $key }}')" class="ot-btn ot-btn-secondary w-11 shrink-0 px-0" aria-label="{{ __('Remove') }}">@svg('heroicon-o-trash', 'size-5')</button>
                </div>
                @if (isset($item['product_id']))
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-1">
                            <label class="mb-1 block text-xs font-semibold text-muted-fg" for="rl-{{ $key }}">{{ __('Lot') }}</label>
                            <input id="rl-{{ $key }}" wire:model="items.{{ $key }}.lot" class="ot-input ot-code" dir="ltr">
                        </div>
                        <div class="col-span-1">
                            <label class="mb-1 block text-xs font-semibold text-muted-fg" for="re-{{ $key }}">{{ __('Expiry') }}</label>
                            <x-ops.date-input id="re-{{ $key }}" wire:model="items.{{ $key }}.expiry_date" />
                        </div>
                        <div class="col-span-1">
                            <label class="mb-1 block text-xs font-semibold text-muted-fg" for="rq-{{ $key }}">{{ __('Qty') }}</label>
                            <input id="rq-{{ $key }}" type="number" min="1" inputmode="numeric" wire:model="items.{{ $key }}.quantity" class="ot-input text-center" dir="ltr">
                        </div>
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-muted-fg" for="rr-{{ $key }}">{{ __('Reason') }}</label>
                        <select id="rr-{{ $key }}" wire:model="items.{{ $key }}.reason" class="ot-input">
                            @foreach (\App\Enums\ReturnReason::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->getLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-muted-fg" for="rc-{{ $key }}">{{ __('Condition') }}</label>
                        <select id="rc-{{ $key }}" wire:model="items.{{ $key }}.condition" class="ot-input">
                            @foreach (\App\Enums\ReturnCondition::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->getLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </article>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-arrow-uturn-left" :title="__('Nothing added yet')" /></div>
        @endforelse
    </section>

    <section class="space-y-4">
        <div>
            <label for="ret-customer" class="mb-1 block text-sm font-semibold">{{ __('Hospital / customer (optional)') }}</label>
            <select id="ret-customer" wire:model="customerId" class="ot-input">
                <option value="">—</option>
                @foreach ($customers as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="ret-notes" class="mb-1 block text-sm font-semibold">{{ __('Note (optional)') }}</label>
            <textarea id="ret-notes" wire:model="notes" rows="2" class="ot-input py-3"></textarea>
        </div>
    </section>

    @if ($items)
        <button type="button" wire:click="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-check', 'size-6')
            <span>{{ __('Record return') }}</span>
        </button>
    @endif
</div>

<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <a href="{{ route('ops.cash') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Cash desk') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('New sale') }}</h1>
    </header>

    @if ($currencies === [])
        <div class="ot-card"><x-ops.empty-state icon="heroicon-o-wallet" :title="__('No cash box in this branch')" :description="__('An admin opens one in Finance → Cash boxes.')" /></div>
    @else
        <fieldset>
            <legend class="mb-1 text-sm font-semibold">{{ __('Paid in') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach ($currencies as $code)
                    <label class="ot-card flex min-h-12 cursor-pointer items-center gap-2 px-4 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                        <input type="radio" wire:model.live="currency" value="{{ $code }}" class="size-5">
                        <x-code :value="$code" class="font-semibold" />
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="mb-1 text-sm font-semibold">{{ __('Price') }}</legend>
            <div class="grid grid-cols-2 gap-2">
                <label class="ot-card flex min-h-12 cursor-pointer items-center gap-2 px-3 text-sm has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                    <input type="radio" wire:model.live="pricing" value="per_item" class="size-5">
                    <span class="font-semibold">{{ __('Per item') }}</span>
                </label>
                <label class="ot-card flex min-h-12 cursor-pointer items-center gap-2 px-3 text-sm has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                    <input type="radio" wire:model.live="pricing" value="split" class="size-5">
                    <span class="font-semibold">{{ __('One price, split by %') }}</span>
                </label>
            </div>
            @if ($pricing === 'split')
                <p class="mt-1 text-xs text-muted-fg">{{ __('Type one price for all the pieces. Each piece gets the percentage of what it cost us; you can change the percentages.') }}</p>
            @endif
        </fieldset>

        <x-ops.scan-box id="sale-code" />

        <section class="space-y-3" aria-label="{{ __('Items') }}">
            @forelse ($items as $key => $item)
                <div wire:key="si-{{ $key }}" class="ot-card space-y-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $item['label'] }}</p>
                            <x-code :value="$item['code']" class="text-sm" />
                        </div>
                        <button type="button" wire:click="remove('{{ $key }}')" class="ot-btn ot-btn-secondary w-11 shrink-0 px-0" aria-label="{{ __('Remove') }}">@svg('heroicon-o-trash', 'size-5')</button>
                    </div>
                    <div class="flex items-end justify-between gap-3">
                        @if ($pricing === 'split')
                            @php($row = $split[$key] ?? null)
                            <div class="min-w-0 flex-1 space-y-1">
                                <label for="share-{{ $key }}" class="block text-sm font-semibold">{{ __('Share %') }}</label>
                                <input id="share-{{ $key }}" wire:model.live.debounce.500ms="shares.{{ $key }}" inputmode="decimal" data-western-digits dir="ltr" class="ot-input"
                                       placeholder="{{ $row ? $row['cost_share'] : '' }}" autocomplete="off">
                                @if ($row)
                                    <p class="text-xs text-muted-fg">
                                        {{ __('Cost share') }} <span dir="ltr">{{ \App\Support\Locale\Display::number($row['cost_share'], 2) }} %</span>
                                        @if ($canSeeCost) · {{ __('cost') }} <x-money :value="$row['cost']" :currency="$currency" /> @endif
                                    </p>
                                    @if ($row['amount'] !== null)
                                        <p class="text-sm font-semibold">{{ __('Gets') }} <x-money :value="$row['amount']" :currency="$currency" /> <span class="text-muted-fg" dir="ltr">({{ \App\Support\Locale\Display::number($row['share'], 2) }} %)</span></p>
                                    @endif
                                @endif
                            </div>
                        @else
                            <div class="min-w-0 flex-1">
                                <label for="price-{{ $key }}" class="mb-1 block text-sm font-semibold">{{ __('Price (:currency)', ['currency' => $currency]) }}</label>
                                <input id="price-{{ $key }}" wire:model.live.debounce.400ms="items.{{ $key }}.price" inputmode="decimal" data-western-digits dir="ltr" class="ot-input" autocomplete="off">
                            </div>
                        @endif
                        @if ($item['batch_id'])
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" wire:click="change('{{ $key }}', -1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('Less') }}">@svg('heroicon-o-minus', 'size-5')</button>
                                <span class="w-8 text-center font-bold"><x-num :value="$item['quantity']" /></span>
                                <button type="button" wire:click="change('{{ $key }}', 1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('More') }}">@svg('heroicon-o-plus', 'size-5')</button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="ot-card"><x-ops.empty-state icon="ot-barcode" :title="__('Nothing scanned yet')" /></div>
            @endforelse
        </section>

        @if ($items)
            <section class="space-y-4">
                <div>
                    <label for="sale-customer" class="mb-1 block text-sm font-semibold">{{ __('Hospital / customer (optional)') }}</label>
                    <select id="sale-customer" wire:model.live="customerId" class="ot-input">
                        <option value="">{{ __('Walk-in buyer') }}</option>
                        @foreach ($customers as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @unless ($customerId)
                    <div>
                        <label for="sale-walkin" class="mb-1 block text-sm font-semibold">{{ __('Buyer name (optional)') }}</label>
                        <input id="sale-walkin" wire:model="walkIn" class="ot-input" autocomplete="off">
                    </div>
                @endunless
                @if ($pricing === 'split')
                    <div>
                        <label for="sale-total" class="mb-1 block text-sm font-semibold">{{ __('Price for all the pieces (:currency)', ['currency' => $currency]) }}</label>
                        <input id="sale-total" wire:model.live.debounce.500ms="saleTotal" inputmode="decimal" data-western-digits dir="ltr" class="ot-input text-lg font-semibold" autocomplete="off">
                        @if ($shares)
                            <button type="button" wire:click="resetShares" class="mt-2 text-sm font-semibold text-primary">{{ __('Use the cost shares again') }}</button>
                        @endif
                    </div>
                @else
                    <div>
                        <label for="sale-discount" class="mb-1 block text-sm font-semibold">{{ __('Discount') }}</label>
                        <input id="sale-discount" wire:model.live.debounce.400ms="discount" inputmode="decimal" data-western-digits dir="ltr" class="ot-input" autocomplete="off">
                    </div>
                @endif
            </section>

            <label class="ot-card flex min-h-14 cursor-pointer items-center gap-3 p-4">
                <input type="checkbox" wire:model.live="paidNow" class="size-6 rounded">
                <span class="flex-1">
                    <span class="block font-semibold">{{ __('Already paid') }}</span>
                    <span class="block text-sm text-muted-fg">
                        {{ $paidNow ? __('The money goes into the :currency box now.', ['currency' => $currency]) : __('The invoice starts unpaid. Mark it paid in Invoices when the money comes.') }}
                    </span>
                </span>
            </label>

            <div class="ot-safe-bottom sticky bottom-20 z-20">
                <div class="ot-card flex items-center justify-between gap-4 p-4 shadow-lg">
                    <div>
                        <p class="text-sm text-muted-fg">{{ __('Total') }}</p>
                        @if ($total !== null)
                            <x-money :value="$total" :currency="$currency" class="text-2xl font-bold" />
                        @else
                            <p class="text-sm font-semibold text-warning">{{ $pricing === 'split' ? __('Type the price for all the pieces') : __('Type the missing prices') }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary px-6 py-4 text-lg">
                        @svg($paidNow ? 'heroicon-o-banknotes' : 'heroicon-o-document-text', 'size-6')
                        <span>{{ $paidNow ? __('Take payment') : __('Save invoice') }}</span>
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>

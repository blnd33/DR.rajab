<div class="space-y-5">
    <a href="{{ route('ops.cash') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Cash') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Close the day') }}</h1>
        <p class="mt-1 text-muted-fg">{{ $box->name }} · <x-date :value="$today" /></p>
    </header>

    @if ($done)
        <section class="ot-card space-y-3 p-4">
            <x-ops.status-chip :label="__('Closed for today')" color="success" icon="heroicon-m-check-circle" />
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <dt class="text-muted-fg">{{ __('Expected') }}</dt>
                <dd class="text-end font-semibold"><x-money :value="$done->expected_balance" :currency="$box->currency" /></dd>
                <dt class="text-muted-fg">{{ __('Counted') }}</dt>
                <dd class="text-end font-semibold"><x-money :value="$done->counted_balance" :currency="$box->currency" /></dd>
                <dt class="text-muted-fg">{{ __('Difference') }}</dt>
                <dd class="text-end font-bold"><x-money :value="$done->difference" :currency="$box->currency" /></dd>
            </dl>
            @if ($done->difference_reason)
                <p class="text-sm">{{ $done->difference_reason }}</p>
            @endif
        </section>
    @else
        <section class="ot-card space-y-1 p-4">
            <p class="text-sm text-muted-fg">{{ __('Expected in the drawer') }}</p>
            <x-money :value="$expected" :currency="$box->currency" class="text-3xl font-bold" />
        </section>

        <section class="space-y-4">
            <div>
                <label for="cd-counted" class="mb-1 block text-sm font-semibold">{{ __('Counted in the drawer') }}</label>
                <input id="cd-counted" wire:model.live.debounce.400ms="counted" inputmode="decimal" data-western-digits dir="ltr" class="ot-input text-2xl font-bold" autocomplete="off" required>
            </div>

            @if ($difference !== null)
                @php($zero = \Brick\Math\BigDecimal::of($difference)->isZero())
                <div @class(['ot-card flex items-center justify-between gap-3 p-4', 'border-success' => $zero, 'border-danger' => ! $zero])>
                    <x-ops.status-chip :label="$zero ? __('Matches') : __('Difference')" :color="$zero ? 'success' : 'danger'" :icon="$zero ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'" />
                    <x-money :value="$difference" :currency="$box->currency" class="text-xl font-bold" />
                </div>
                @unless ($zero)
                    <div>
                        <label for="cd-reason" class="mb-1 block text-sm font-semibold">{{ __('Reason for a difference') }}</label>
                        <textarea id="cd-reason" wire:model="reason" rows="3" class="ot-input py-3" required></textarea>
                        <p class="mt-1 text-sm text-muted-fg">{{ __('The difference is booked and your manager is told.') }}</p>
                    </div>
                @endunless
            @endif
        </section>

        <button type="button" wire:click="submit" wire:loading.attr="disabled" @disabled($difference === null) class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-lock-closed', 'size-6')
            <span>{{ __('Submit and lock') }}</span>
        </button>
    @endif
</div>

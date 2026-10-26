<div class="space-y-5">
    <a href="{{ route('ops.cash') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Cash') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Exchange currency') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('Money moves between your own boxes at the rate you got.') }}</p>
    </header>

    <form wire:submit="submit" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="fx-from" class="mb-1 block text-sm font-semibold">{{ __('Give from') }}</label>
                <select id="fx-from" wire:model.live="fromId" class="ot-input">
                    @foreach ($boxes as $box)
                        <option value="{{ $box->id }}">{{ $box->name }} · {{ \App\Support\Locale\Display::money($box->current_balance, $box->currency) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="fx-to" class="mb-1 block text-sm font-semibold">{{ __('Into') }}</label>
                <select id="fx-to" wire:model.live="toId" class="ot-input">
                    @foreach ($boxes as $box)
                        @if (! $from || $box->currency !== $from->currency)
                            <option value="{{ $box->id }}">{{ $box->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label for="fx-amount" class="mb-1 block text-sm font-semibold">{{ __('Amount given (:currency)', ['currency' => $from?->currency ?? '…']) }}</label>
            <input id="fx-amount" wire:model.live.debounce.400ms="amount" inputmode="decimal" data-western-digits dir="ltr" class="ot-input text-xl font-bold" autocomplete="off" required>
        </div>
        <div>
            <label for="fx-rate" class="mb-1 block text-sm font-semibold">{{ __('Deal rate') }}</label>
            <input id="fx-rate" wire:model.live.debounce.400ms="rate" inputmode="decimal" data-western-digits dir="ltr" class="ot-input" autocomplete="off" required>
            @if ($reference && $from && $to)
                <p class="mt-1 text-sm text-muted-fg">
                    {{ __('Reference today: 1 :from = :rate :to', ['from' => $from->currency, 'to' => $to->currency, 'rate' => \App\Support\Locale\Display::digits($reference)]) }}
                </p>
            @endif
        </div>
        <div>
            <label for="fx-note" class="mb-1 block text-sm font-semibold">{{ __('Note') }}</label>
            <input id="fx-note" wire:model="note" class="ot-input" autocomplete="off">
        </div>

        @if ($received !== null && $to)
            <section class="ot-card flex items-center justify-between gap-3 p-4">
                <span class="text-sm text-muted-fg">{{ __('You receive') }}</span>
                <x-money :value="$received" :currency="$to->currency" class="text-xl font-bold" />
            </section>
        @endif

        <button type="submit" wire:loading.attr="disabled" @disabled(! $to) class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-arrows-right-left', 'size-6')
            <span>{{ __('Exchange') }}</span>
        </button>
    </form>
</div>

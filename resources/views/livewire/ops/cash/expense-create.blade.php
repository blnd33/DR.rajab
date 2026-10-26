<div class="space-y-5">
    <a href="{{ route('ops.cash') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Cash desk') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Record an expense') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('Large amounts wait for approval before the money leaves the box.') }}</p>
    </header>

    <form wire:submit="submit" class="space-y-4">
        <div>
            <label for="ex-box" class="mb-1 block text-sm font-semibold">{{ __('Paid from') }}</label>
            <select id="ex-box" wire:model.live="boxId" class="ot-input" required>
                @foreach ($boxes as $box)
                    <option value="{{ $box->id }}">{{ $box->name }} · {{ \App\Support\Locale\Display::money($box->current_balance, $box->currency) }}</option>
                @endforeach
            </select>
            @error('boxId') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="ex-category" class="mb-1 block text-sm font-semibold">{{ __('Category') }}</label>
            <select id="ex-category" wire:model="categoryId" class="ot-input" required>
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            @error('categoryId') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="ex-amount" class="mb-1 block text-sm font-semibold">
                {{ __('Amount (:currency)', ['currency' => $boxes->firstWhere('id', $boxId)?->currency ?? '…']) }}
            </label>
            <input id="ex-amount" wire:model="amount" inputmode="decimal" data-western-digits dir="ltr" class="ot-input text-xl font-bold" autocomplete="off" required>
            @error('amount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="ex-payee" class="mb-1 block text-sm font-semibold">{{ __('Paid to') }}</label>
            <input id="ex-payee" wire:model="payee" class="ot-input" autocomplete="off">
        </div>
        <div>
            <label for="ex-description" class="mb-1 block text-sm font-semibold">{{ __('Description') }}</label>
            <textarea id="ex-description" wire:model="description" rows="2" class="ot-input py-3"></textarea>
        </div>
        <div>
            <label for="ex-receipts" class="mb-1 block text-sm font-semibold">{{ __('Receipt photo (optional)') }}</label>
            <input id="ex-receipts" type="file" wire:model="receipts" multiple accept="image/*,application/pdf" capture="environment" class="block w-full text-sm">
            <div wire:loading wire:target="receipts" class="mt-1 text-sm text-muted-fg">{{ __('Uploading…') }}</div>
            @error('receipts.*') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-check', 'size-6')
            <span>{{ __('Save expense') }}</span>
        </button>
    </form>
</div>

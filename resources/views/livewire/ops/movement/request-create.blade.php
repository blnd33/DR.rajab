<div class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Request stock') }}</h1>
    </header>

    @if ($lowCount > 0)
        <button type="button" wire:click="fillFromLowStock" class="ot-btn ot-btn-secondary w-full">
            @svg('heroicon-o-arrow-trending-down', 'size-5 text-danger')
            <span>{{ __('Add everything below minimum (:count)', ['count' => \App\Support\Locale\Display::number($lowCount)]) }}</span>
        </button>
    @endif

    <section class="ot-card space-y-2 p-4">
        <label for="req-search" class="block text-sm font-semibold">{{ __('Add a product') }}</label>
        <input id="req-search" type="search" wire:model.live.debounce.300ms="search" class="ot-input" autocomplete="off" placeholder="{{ __('SKU or name') }}">
        @if ($matches->isNotEmpty())
            <ul class="divide-y divide-border rounded-lg border border-border">
                @foreach ($matches as $match)
                    <li>
                        <button type="button" wire:click="add({{ $match->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-subtle">
                            <span class="min-w-0"><x-code :value="$match->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $match->name }}@if ($match->size) · <bdi class="whitespace-nowrap">{{ $match->size }}</bdi> @endif</span></span>
                            @svg('heroicon-o-plus-circle', 'size-6 shrink-0 text-primary')
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="space-y-3" aria-labelledby="req-lines">
        <h2 id="req-lines" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Products') }}</h2>
        @forelse ($lines as $productId => $qty)
            @php($product = $products[$productId] ?? null)
            @if ($product)
                <div wire:key="l-{{ $productId }}" class="ot-card flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $product->name }}@if ($product->size) · <bdi class="whitespace-nowrap">{{ $product->size }}</bdi> @endif</p>
                        <x-code :value="$product->sku" class="text-sm text-muted-fg" />
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" wire:click="change({{ $productId }}, -1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('Less') }}">@svg('heroicon-o-minus', 'size-5')</button>
                        <span class="w-10 text-center text-lg font-bold"><x-num :value="$qty" /></span>
                        <button type="button" wire:click="change({{ $productId }}, 1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('More') }}">@svg('heroicon-o-plus', 'size-5')</button>
                    </div>
                </div>
            @endif
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-inbox" :title="__('No products yet')" :description="__('Search above, or add everything below minimum.')" /></div>
        @endforelse
    </section>

    <section class="space-y-4">
        <div>
            <label for="source" class="mb-1 block text-sm font-semibold">{{ __('Ask which station? (optional)') }}</label>
            <select id="source" wire:model="sourceBranchId" class="ot-input">
                <option value="">{{ __('Any — the admin decides') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->code }} — {{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <fieldset>
            <legend class="mb-1 text-sm font-semibold">{{ __('Priority') }}</legend>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Enums\RequestPriority::cases() as $case)
                    <label class="ot-card flex min-h-12 cursor-pointer items-center gap-2 px-3 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                        <input type="radio" wire:model="priority" value="{{ $case->value }}" class="size-5">
                        <span class="font-semibold">{{ $case->getLabel() }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div>
            <label for="needed" class="mb-1 block text-sm font-semibold">{{ __('Needed by (optional)') }}</label>
            <x-ops.date-input id="needed" wire:model="neededBy" />
        </div>
        <div>
            <label for="req-notes" class="mb-1 block text-sm font-semibold">{{ __('Note (optional)') }}</label>
            <textarea id="req-notes" wire:model="notes" rows="2" class="ot-input py-3"></textarea>
        </div>
    </section>

    @if ($lines)
        <button type="button" wire:click="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-paper-airplane', 'size-6 rtl:-scale-x-100')
            <span>{{ __('Send request') }}</span>
        </button>
    @endif
</div>

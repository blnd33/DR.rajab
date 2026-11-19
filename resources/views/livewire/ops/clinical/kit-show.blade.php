<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <a href="{{ route('ops.kits.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Instrument sets') }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ $kit->type->name }}</p>
        <h1 class="text-2xl font-bold"><x-code :value="$kit->code" /></h1>
        <p class="flex flex-wrap items-center gap-2 text-muted-fg">
            <span class="font-semibold text-fg">{{ $kit->branch->code }}</span>
            <x-ops.status-chip :label="$kit->status->getLabel()" :color="$kit->status->getColor()" :icon="$kit->status->getIcon()" />
            @if ($kit->customer) <span>{{ $kit->customer->name }}</span> @endif
            @if ($kit->isOverdue()) <x-ops.status-chip :label="__('Overdue')" color="danger" icon="heroicon-m-clock" /> @endif
        </p>
    </header>

    {{-- What can be done now --}}
    <nav class="grid grid-cols-2 gap-2" aria-label="{{ __('Actions') }}">
        <button type="button" wire:click="setMode('overview')" @class(['ot-btn', 'ot-btn-primary' => $mode === 'overview', 'ot-btn-secondary' => $mode !== 'overview'])>{{ __('Contents') }}</button>
        @if ($canPrepare)
            <button type="button" wire:click="setMode('prepare')" @class(['ot-btn', 'ot-btn-primary' => $mode === 'prepare', 'ot-btn-secondary' => $mode !== 'prepare'])>{{ __('Prepare / refill') }}</button>
        @endif
        @if ($canCheckout)
            <button type="button" wire:click="setMode('checkout')" @class(['ot-btn', 'ot-btn-primary' => $mode === 'checkout', 'ot-btn-secondary' => $mode !== 'checkout'])>{{ __('Check out') }}</button>
        @endif
        @if ($canReturn)
            <button type="button" wire:click="setMode('return')" @class(['ot-btn', 'ot-btn-primary' => $mode === 'return', 'ot-btn-secondary' => $mode !== 'return'])>{{ __('Return') }}</button>
        @endif
    </nav>

    @if ($mode === 'prepare' && $canPrepare)
        @if ($fillPlan !== null && $fillPlan['reuse'] + $fillPlan['register'] > 0)
            <section class="ot-card space-y-3 p-4" aria-labelledby="fill-title">
                <h2 id="fill-title" class="font-semibold">{{ __('Fill from its list') }}</h2>
                <p class="text-sm text-muted-fg">
                    {{ __('In total: :reuse from the shelf, :register registered new.', ['reuse' => \App\Support\Locale\Display::number($fillPlan['reuse']), 'register' => \App\Support\Locale\Display::number($fillPlan['register'])]) }}
                </p>
                <button type="button" wire:click="fillFromList" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full"
                    wire:confirm="{{ __('Put :count instruments into the instrument set?', ['count' => \App\Support\Locale\Display::number($fillPlan['reuse'] + $fillPlan['register'])]) }}">
                    @svg('heroicon-o-queue-list', 'size-5')
                    <span>{{ __('Fill the instrument set') }}</span>
                </button>
            </section>
        @endif

        @if ($labelsUrl)
            <a href="{{ $labelsUrl }}" target="_blank" rel="noopener" class="ot-btn ot-btn-secondary w-full">
                @svg('heroicon-o-printer', 'size-5')
                <span>{{ __('Print labels for the new instruments') }}</span>
            </a>
        @endif

        <x-ops.scan-box id="kit-prepare-code" :hint="__('Scan each item')">
            <button type="button" wire:click="markPrepared" class="ot-btn ot-btn-secondary w-full">
                @svg('heroicon-o-clipboard-document-check', 'size-5')
                <span>{{ __('Mark as prepared') }}</span>
            </button>
        </x-ops.scan-box>
    @endif

    @if ($mode === 'checkout' && $canCheckout)
        <section class="ot-card space-y-4 p-4" aria-labelledby="checkout-title">
            <h2 id="checkout-title" class="font-semibold">{{ __('Check out to hospital') }}</h2>
            @unless ($compare['complete'])
                <p class="text-sm font-semibold text-warning">{{ __('The instrument set is not complete — refill it from the station shelf.') }}</p>
            @endunless
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Hospital') }}</span>
                <select wire:model="customerId" class="ot-input">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($hospitals as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('customerId') <span class="text-sm text-danger">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Doctor') }}</span>
                <select wire:model="doctorId" class="ot-input">
                    <option value="">—</option>
                    @foreach ($doctors as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Planned surgery date') }}</span>
                <x-ops.date-input wire:model="plannedDate" />
            </label>
            <button type="button" wire:click="checkout" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full">
                @svg('heroicon-o-arrow-up-tray', 'size-5')
                <span>{{ __('Check out') }}</span>
            </button>
        </section>
    @endif

    @if ($mode === 'return' && $canReturn)
        <x-ops.scan-box id="kit-return-code" :hint="__('Scan what came back')" />

        <section class="space-y-3" aria-labelledby="return-title">
            <h2 id="return-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('What went out') }} · <x-code :value="$checkout->number" /></h2>
            @foreach ($returnItems as $item)
                <article wire:key="ri-{{ $item->id }}" class="ot-card space-y-2 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $item->name() }}</p>
                            <p class="text-sm text-muted-fg"><x-code :value="$item->code()" /></p>
                        </div>
                        @if ($item->item_kind === \App\Enums\CheckoutItemKind::Unit)
                            @if ($item->stockUnit->status === \App\Enums\StockStatus::UsedInSurgery)
                                <x-ops.status-chip :label="__('Used in surgery')" color="primary" icon="heroicon-m-heart" class="shrink-0" />
                            @elseif (in_array($item->stock_unit_id, $returnedUnits, true))
                                <x-ops.status-chip :label="__('Back')" color="success" icon="heroicon-m-check-circle" class="shrink-0" />
                            @else
                                <x-ops.status-chip :label="__('Not scanned yet')" icon="heroicon-o-clock" class="shrink-0" />
                            @endif
                        @endif
                    </div>
                    @if ($item->item_kind === \App\Enums\CheckoutItemKind::Instrument)
                        <div class="grid grid-cols-3 gap-2 text-sm">
                            @foreach (['returned' => __('Returned'), 'missing' => __('Missing'), 'damaged' => __('Damaged')] as $value => $label)
                                <label @class(['ot-btn min-h-10 px-2', 'ot-btn-primary' => ($instrumentResults[$item->instrument_id] ?? '') === $value, 'ot-btn-secondary' => ($instrumentResults[$item->instrument_id] ?? '') !== $value])>
                                    <input type="radio" class="sr-only" value="{{ $value }}" wire:model.live="instrumentResults.{{ $item->instrument_id }}">{{ $label }}
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @if ($item->item_kind === \App\Enums\CheckoutItemKind::Batch)
                        <label class="flex items-center justify-between gap-3 text-sm">
                            <span>{{ __('Came back (out: :out)', ['out' => \App\Support\Locale\Display::number($item->qty_out)]) }}</span>
                            <input type="number" inputmode="numeric" min="0" max="{{ $item->stockBatch->quantity }}" wire:model="lotReturned.{{ $item->id }}" class="ot-input w-24 text-center" dir="ltr" data-western-digits>
                        </label>
                    @endif
                </article>
            @endforeach

            <button type="button" wire:click="confirmReturn" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full"
                    wire:confirm="{{ __('Finish the return? Implants not scanned and not used in a logged surgery become unaccounted.') }}">
                @svg('heroicon-o-arrow-down-tray', 'size-5')
                <span>{{ __('Finish return') }}</span>
            </button>
        </section>
    @endif

    @if ($mode === 'overview' || $mode === 'prepare')
        {{-- Against the template --}}
        <section class="ot-card space-y-3 p-4" aria-labelledby="template-title">
            <h2 id="template-title" class="font-semibold">{{ __('Against the template') }}</h2>
            <ul class="divide-y divide-border">
                @forelse ($compare['lines'] as $line)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">{{ $line['label'] }}</span>
                        <span @class(['shrink-0 font-bold', 'text-success' => $line['missing'] === 0, 'text-danger' => $line['missing'] > 0 && $line['required'], 'text-warning' => $line['missing'] > 0 && ! $line['required']])>
                            <x-num :value="$line['present']" /> / <x-num :value="$line['expected']" />
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-muted-fg">{{ __('This instrument set type has no template yet.') }}</li>
                @endforelse
            </ul>
            <p @class(['text-sm font-semibold', 'text-success' => $compare['complete'], 'text-danger' => ! $compare['complete']])>
                {{ $compare['complete'] ? __('The instrument set is complete.') : __('The instrument set is not complete — refill it from the station shelf.') }}
            </p>
        </section>

        {{-- Contents --}}
        <section class="space-y-3" aria-labelledby="contents-title">
            <h2 id="contents-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('In the instrument set') }}</h2>
            @foreach ($instruments as $instrument)
                <div wire:key="in-{{ $instrument->id }}" class="ot-card flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $instrument->type->name }}</p>
                        <p class="text-sm text-muted-fg"><x-code :value="$instrument->code" /></p>
                    </div>
                    @if ($mode === 'prepare')
                        <button type="button" wire:click="removeInstrument({{ $instrument->id }})" class="ot-btn ot-btn-secondary min-h-11 px-3 text-sm">{{ __('Take out') }}</button>
                    @endif
                </div>
            @endforeach
            @foreach ($units as $unit)
                <div wire:key="un-{{ $unit->id }}" class="ot-card flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-semibold">{{ $unit->product->name }}@if ($unit->product->size) · <bdi class="whitespace-nowrap">{{ $unit->product->size }}</bdi> @endif</p>
                        <p class="text-sm text-muted-fg"><x-code :value="$unit->serial" /></p>
                        <x-expiry-chip :date="$unit->expiry_date" />
                    </div>
                    @if ($mode === 'prepare')
                        <button type="button" wire:click="removeUnit({{ $unit->id }})" class="ot-btn ot-btn-secondary min-h-11 px-3 text-sm">{{ __('Take out') }}</button>
                    @endif
                </div>
            @endforeach
            @foreach ($batches as $batch)
                <div wire:key="ba-{{ $batch->id }}" class="ot-card flex items-center justify-between gap-3 p-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-semibold">{{ $batch->product->name }}@if ($batch->product->size) · <bdi class="whitespace-nowrap">{{ $batch->product->size }}</bdi> @endif</p>
                        <p class="text-sm text-muted-fg"><x-code :value="$batch->codeLabel()" /> × <x-num :value="$batch->quantity" /></p>
                        <x-expiry-chip :date="$batch->expiry_date" />
                    </div>
                    @if ($mode === 'prepare' && $batch->location_type === \App\Enums\LocationType::Kit)
                        <button type="button" wire:click="removeBatch({{ $batch->id }})" class="ot-btn ot-btn-secondary min-h-11 px-3 text-sm">{{ __('Take out') }}</button>
                    @endif
                </div>
            @endforeach
            @if ($instruments->isEmpty() && $units->isEmpty() && $batches->isEmpty())
                <div class="ot-card"><x-ops.empty-state icon="heroicon-o-briefcase" :title="__('The instrument set is empty')" /></div>
            @endif
        </section>
    @endif
</div>

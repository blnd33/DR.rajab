<div class="space-y-5">
    <a href="{{ route('ops.surgeries.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('My surgeries') }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Surgery') }}</p>
        <h1 class="text-2xl font-bold">{{ $surgery->type?->name ?? __('Surgery draft') }}</h1>
        <p class="flex flex-wrap items-center gap-2 text-muted-fg">
            <x-code :value="$surgery->number" />
            <x-date :value="$surgery->surgery_date" />
            <x-ops.status-chip :label="$surgery->status->getLabel()" :color="$surgery->status->getColor()" :icon="$surgery->status->getIcon()" />
        </p>
    </header>

    @if ($surgery->problems)
        <div class="ot-card border-danger space-y-2 p-4" role="alert">
            <p class="font-semibold text-danger">{{ __('Not completed yet — fix these:') }}</p>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($surgery->problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
            <a href="{{ route('ops.surgeries.create', ['surgery' => $surgery->id]) }}" class="ot-btn ot-btn-secondary w-full">
                @svg('heroicon-o-pencil-square', 'size-5')
                <span>{{ __('Fix on the phone') }}</span>
            </a>
        </div>
    @endif

    <section class="ot-card divide-y divide-border">
        <dl class="grid grid-cols-2 gap-3 p-4 text-sm">
            <div><dt class="text-muted-fg">{{ __('Patient') }}</dt><dd class="font-semibold">{{ $surgery->patientName() ?? '—' }}</dd></div>
            <div><dt class="text-muted-fg">{{ __('Hospital') }}</dt><dd class="font-semibold">{{ $surgery->customer?->name ?? '—' }}</dd></div>
            <div><dt class="text-muted-fg">{{ __('Side') }}</dt><dd class="font-semibold">{{ $surgery->laterality->getLabel() }}</dd></div>
            <div><dt class="text-muted-fg">{{ __('Branch') }}</dt><dd class="font-semibold">{{ $surgery->branch->code }}</dd></div>
            <div class="col-span-2"><dt class="text-muted-fg">{{ __('Doctors') }}</dt><dd class="font-semibold">{{ $surgery->doctors->pluck('name')->implode(', ') }}</dd></div>
            @if ($surgery->kitCheckout)
                <div class="col-span-2"><dt class="text-muted-fg">{{ __('Kit') }}</dt><dd><x-code :value="$surgery->kitCheckout->kit->code" class="font-semibold" /></dd></div>
            @endif
            @if ($surgery->notes)
                <div class="col-span-2"><dt class="text-muted-fg">{{ __('Notes') }}</dt><dd>{{ $surgery->notes }}</dd></div>
            @endif
        </dl>
    </section>

    <section class="space-y-3" aria-labelledby="items-title">
        <h2 id="items-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Implants and products used') }}</h2>
        @forelse ($surgery->items as $item)
            <article wire:key="si-{{ $item->id }}" @class(['ot-card space-y-1 p-4', 'opacity-60' => $item->reversed_at])>
                <p class="font-semibold">{{ $item->product->name }}@if ($item->product->size) · <bdi class="whitespace-nowrap">{{ $item->product->size }}</bdi> @endif</p>
                <p class="text-sm text-muted-fg"><x-code :value="$item->product->sku" /> · <x-code :value="$item->code()" class="font-semibold text-fg" />@if ($item->quantity > 1) × <x-num :value="$item->quantity" />@endif</p>
                @if ($item->stockUnit ?? $item->stockBatch)
                    <x-expiry-chip :date="($item->stockUnit ?? $item->stockBatch)->expiry_date" />
                @endif
                @foreach ($item->warnings ?? [] as $warning)
                    <x-ops.status-chip :label="\App\Services\Clinical\SurgeryService::warningLabel($warning)" color="warning" icon="heroicon-m-exclamation-triangle" />
                @endforeach
                @if ($item->reversed_at)
                    <p class="text-xs text-muted-fg">{{ __('Put back on the shelf') }}: {{ $item->reversal_reason }}</p>
                @endif
            </article>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="ot-barcode" :title="__('Nothing scanned yet')" /></div>
        @endforelse
    </section>

    @if ($surgery->attachments->isNotEmpty() || $canPhoto)
        <section class="ot-card space-y-3 p-4" aria-labelledby="photos-title">
            <h2 id="photos-title" class="font-semibold">{{ __('Photos') }}</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($surgery->attachments as $photo)
                    <a href="{{ route('attachments.show', $photo) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg ring-1 ring-border">
                        <img src="{{ route('attachments.show', $photo) }}" alt="{{ $photo->original_name }}" class="size-24 object-cover" loading="lazy">
                    </a>
                @endforeach
            </div>
            @if ($canPhoto)
                <label class="ot-btn ot-btn-secondary w-full cursor-pointer">
                    @svg('heroicon-o-camera', 'size-5')
                    <span wire:loading.remove wire:target="photos">{{ __('Add photos of the implant stickers') }}</span>
                    <span wire:loading wire:target="photos">{{ __('Uploading…') }}</span>
                    <input type="file" accept="image/*" capture="environment" multiple wire:model="photos" class="sr-only">
                </label>
            @endif
        </section>
    @endif

    @if ($canComplete)
        <section class="ot-card space-y-3 p-4">
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Reason for expired items') }}</span>
                <input type="text" wire:model="overrideReason" class="ot-input" maxlength="500">
            </label>
            <button type="button" wire:click="complete" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full"
                    wire:confirm="{{ __('Complete the surgery? Every item leaves stock now.') }}">
                @svg('heroicon-o-check', 'size-5')
                <span>{{ __('Complete surgery') }}</span>
            </button>
        </section>
    @endif

    @if ($canCancelDraft)
        <details class="ot-card p-4">
            <summary class="cursor-pointer text-sm font-semibold text-danger">{{ __('Cancel this draft') }}</summary>
            <div class="mt-3 space-y-3">
                <input type="text" wire:model="cancelReason" class="ot-input" placeholder="{{ __('Reason') }}" maxlength="500">
                <button type="button" wire:click="cancelDraft" class="ot-btn ot-btn-danger w-full">{{ __('Cancel draft') }}</button>
            </div>
        </details>
    @endif
</div>

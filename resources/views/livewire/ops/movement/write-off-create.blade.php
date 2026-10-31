<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Report damaged / lost') }}</h1>
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
            <label for="wo-code" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
            <input id="wo-code" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" placeholder="{{ __('Scan or type a code') }}">
            <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
        </form>
    </section>

    <section class="space-y-3">
        @forelse ($items as $key => $item)
            <div wire:key="wo-{{ $key }}" class="ot-card flex items-center justify-between gap-3 p-4">
                <div class="min-w-0">
                    <p class="font-semibold">{{ $item['label'] }}</p>
                    <x-code :value="$item['code']" class="text-sm" />
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($item['batch_id'])
                        <button type="button" wire:click="change('{{ $key }}', -1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('Less') }}">@svg('heroicon-o-minus', 'size-5')</button>
                        <span class="w-8 text-center font-bold"><x-num :value="$item['quantity']" /></span>
                        <button type="button" wire:click="change('{{ $key }}', 1)" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('More') }}">@svg('heroicon-o-plus', 'size-5')</button>
                    @endif
                    <button type="button" wire:click="remove('{{ $key }}')" class="ot-btn ot-btn-secondary w-11 px-0" aria-label="{{ __('Remove') }}">@svg('heroicon-o-trash', 'size-5')</button>
                </div>
            </div>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="ot-barcode" :title="__('Nothing scanned yet')" /></div>
        @endforelse
    </section>

    <section class="space-y-4">
        <fieldset>
            <legend class="mb-1 text-sm font-semibold">{{ __('Reason') }}</legend>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Enums\WriteOffReason::cases() as $case)
                    <label class="ot-card flex min-h-12 cursor-pointer items-center gap-2 px-3 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                        <input type="radio" wire:model="reason" value="{{ $case->value }}" class="size-5">
                        <span class="font-semibold">{{ $case->getLabel() }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div>
            <label for="wo-note" class="mb-1 block text-sm font-semibold">{{ __('What happened?') }}</label>
            <textarea id="wo-note" wire:model="note" rows="3" class="ot-input py-3"></textarea>
        </div>
        <div>
            <label for="wo-photos" class="mb-1 block text-sm font-semibold">{{ __('Photos (optional)') }}</label>
            <input id="wo-photos" type="file" wire:model="photos" multiple accept="image/*" capture="environment" class="block w-full text-sm">
            <div wire:loading wire:target="photos" class="mt-1 text-sm text-muted-fg">{{ __('Uploading…') }}</div>
            @error('photos.*') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
    </section>

    @if ($items)
        <button type="button" wire:click="submit" wire:loading.attr="disabled" class="ot-btn ot-btn-primary w-full py-4 text-lg">
            @svg('heroicon-o-paper-airplane', 'size-6 rtl:-scale-x-100')
            <span>{{ __('Send for approval') }}</span>
        </button>
    @endif
</div>

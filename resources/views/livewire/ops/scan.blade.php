<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Scan anything') }}</h1>
    </header>

    {{-- Camera --}}
    <section class="ot-card overflow-hidden" aria-label="{{ __('Camera') }}">
        <div class="relative aspect-[4/3] w-full bg-fg" wire:ignore>
            <video x-ref="video" class="h-full w-full object-cover" playsinline muted
                   x-show="cameraOn" aria-label="{{ __('Camera preview') }}"></video>

            {{-- Aiming frame --}}
            <div x-show="cameraOn" class="pointer-events-none absolute inset-10 rounded-2xl border-4 border-white/80"></div>

            <div x-show="!cameraOn" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-white">
                @svg('heroicon-o-camera', 'size-12 opacity-80')
                <p x-show="!cameraError" class="text-sm opacity-90">{{ __('Camera is off') }}</p>
                <p x-show="cameraError === 'denied'" x-cloak class="text-sm">{{ __('Camera permission was denied. Allow it in the browser settings, or type the code below.') }}</p>
                <p x-show="cameraError === 'unavailable'" x-cloak class="text-sm">{{ __('No camera found on this device. Use a scanner or type the code below.') }}</p>
            </div>
        </div>

        <div class="p-3">
            <button type="button" class="ot-btn w-full" :class="cameraOn ? 'ot-btn-secondary' : 'ot-btn-primary'"
                    @click="toggleCamera()" :disabled="starting">
                @svg('heroicon-o-camera', 'size-5')
                <span x-show="!cameraOn && !starting">{{ __('Start camera') }}</span>
                <span x-show="starting" x-cloak>{{ __('Starting…') }}</span>
                <span x-show="cameraOn" x-cloak>{{ __('Stop camera') }}</span>
            </button>
        </div>
    </section>

    {{-- Manual / hardware scanner input --}}
    <form @submit.prevent="submitManual()" class="ot-card space-y-2 p-4" wire:ignore>
        <label for="scan-input" class="block text-sm font-semibold">{{ __('Code (scanner or keyboard)') }}</label>
        <div class="flex gap-2">
            <input id="scan-input" x-model="manual" data-scan-field data-western-digits
                   class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false"
                   enterkeyhint="search" placeholder="0108712345678906 10ABC123">
            <button type="submit" class="ot-btn ot-btn-primary shrink-0">{{ __('Look up') }}</button>
        </div>
    </form>

    {{-- Results --}}
    <section aria-labelledby="scan-results" class="space-y-3">
        <div class="flex items-center justify-between">
            <h2 id="scan-results" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Scanned') }}</h2>
            @if ($results)
                <button type="button" wire:click="clear" class="min-h-11 px-2 text-sm font-medium text-primary">{{ __('Clear') }}</button>
            @endif
        </div>

        <div wire:loading.flex wire:target="handleScan" class="ot-card items-center gap-3 p-4 text-sm text-muted-fg" role="status">
            @svg('heroicon-o-arrow-path', 'size-5 animate-spin motion-reduce:animate-none')
            <span>{{ __('Looking up…') }}</span>
        </div>

        @forelse ($results as $index => $card)
            <article wire:key="{{ $card['id'] }}" @class([
                'ot-card space-y-3 p-4',
                'border-s-4 border-s-danger' => $card['kind'] === 'unknown' || ($card['unit'] && (! $card['here'] || $card['unit']['blocked'])),
                'border-s-4 border-s-success' => $card['kind'] !== 'unknown' && ! ($card['unit'] && (! $card['here'] || $card['unit']['blocked'])),
            ]) @if ($index === 0) role="status" aria-live="polite" @endif>

                {{-- What it is --}}
                @if ($card['unit'])
                    @php($unit = $card['unit'])
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Serial unit') }}</p>
                            <x-code :value="$unit['serial']" class="block break-all text-lg font-bold" />
                            @if ($card['product'])
                                <p class="text-sm"><x-code :value="$card['product']['sku']" /> · {{ $card['product']['name'] }}@if ($card['product']['size']) · <bdi class="whitespace-nowrap">{{ $card['product']['size'] }}</bdi> @endif</p>
                            @endif
                        </div>
                        <x-ops.status-chip :label="$unit['status_label']" :color="$unit['status_color']" :icon="$unit['status_icon']" class="shrink-0" />
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div><dt class="text-xs text-muted-fg">{{ __('Branch') }}</dt><dd class="font-semibold">{{ $unit['branch'] }}</dd></div>
                        @if ($unit['where'])
                            <div><dt class="text-xs text-muted-fg">{{ __('Where') }}</dt><dd>{{ $unit['where'] }}</dd></div>
                        @endif
                        @if ($unit['lot'])
                            <div><dt class="text-xs text-muted-fg">{{ __('Lot') }}</dt><dd><x-code :value="$unit['lot']" /></dd></div>
                        @endif
                        @if ($unit['expiry'])
                            <div class="col-span-2"><dt class="text-xs text-muted-fg">{{ __('Expiry') }}</dt><dd><x-expiry-chip :date="$unit['expiry']" /></dd></div>
                        @endif
                    </dl>
                    @unless ($card['here'])
                        <p class="flex items-start gap-2 rounded-lg bg-warning-soft p-2 text-sm font-medium text-warning">
                            @svg('heroicon-o-exclamation-triangle', 'mt-0.5 size-5 shrink-0')
                            <span>{{ __('This unit belongs to branch :branch, not the branch you are working in.', ['branch' => $unit['branch']]) }}</span>
                        </p>
                    @endunless
                    @if ($unit['blocked'])
                        <p class="flex items-start gap-2 rounded-lg bg-danger-soft p-2 text-sm font-medium text-danger">
                            @svg('heroicon-o-no-symbol', 'mt-0.5 size-5 shrink-0')
                            <span>{{ __('Expired — cannot be used, sold or transferred.') }}</span>
                        </p>
                    @endif
                    @if ($unit['url'])
                        <a href="{{ $unit['url'] }}" wire:navigate class="ot-btn ot-btn-secondary w-full">
                            @svg('heroicon-o-clock', 'size-5')
                            <span>{{ __('History of this unit') }}</span>
                        </a>
                    @endif

                @elseif ($card['product'])
                    @php($product = $card['product'])
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">
                            {{ $card['kind'] === 'new_unit' ? __('New unit (not in stock yet)') : __('Product') }}
                        </p>
                        <p class="text-lg font-bold">{{ $product['name'] }}</p>
                        <p class="text-sm"><x-code :value="$product['sku']" />@if ($product['size']) · <bdi class="whitespace-nowrap">{{ $product['size'] }}</bdi> @endif</p>
                    </div>

                    @if ($card['lots'])
                        <ul class="divide-y divide-border rounded-lg border border-border">
                            @foreach ($card['lots'] as $lot)
                                <li class="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                                    <span class="min-w-0">
                                        <x-code :value="$lot['lot'] ?? '—'" class="font-semibold" />
                                        <span @class(['text-muted-fg', 'font-semibold text-fg' => $lot['here']])> · {{ $lot['branch'] }}</span>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <x-expiry-chip :date="$lot['expiry']" compact />
                                        <span class="font-bold"><x-num :value="$lot['quantity']" /></span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($card['fefo'])
                        <p class="flex items-start gap-2 rounded-lg bg-primary-soft p-2 text-sm text-primary">
                            @svg('heroicon-o-arrow-down-circle', 'mt-0.5 size-5 shrink-0')
                            <span>{{ __('Use first (FEFO):') }} <x-code :value="$card['fefo']['code']" class="font-semibold" /> · {{ $card['fefo']['where'] }}
                                @if ($card['fefo']['expiry']) · <x-date :value="$card['fefo']['expiry']" /> @endif
                            </span>
                        </p>
                    @endif

                    <div class="flex flex-col gap-2 sm:flex-row">
                        @if ($product['url'])
                            <a href="{{ $product['url'] }}" wire:navigate class="ot-btn ot-btn-secondary flex-1">
                                @svg('heroicon-o-cube', 'size-5')
                                <span>{{ __('Stock of this product') }}</span>
                            </a>
                        @endif
                        @if ($card['kind'] === 'new_unit' && $canReceive)
                            <a href="{{ route('ops.stock.receive', ['code' => $card['raw']]) }}" wire:navigate class="ot-btn ot-btn-primary flex-1">
                                @svg('heroicon-o-inbox-arrow-down', 'size-5')
                                <span>{{ __('Receive this unit') }}</span>
                            </a>
                        @endif
                    </div>

                @elseif ($card['kind'] === 'lot_search')
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Lot number') }}</p>
                        <x-code :value="$card['raw']" class="block text-lg font-bold" />
                    </div>
                    <ul class="divide-y divide-border rounded-lg border border-border text-sm">
                        @foreach ($card['units'] as $u)
                            <li><a href="{{ $u['url'] }}" wire:navigate class="flex min-h-12 items-center justify-between gap-2 p-2">
                                <span><x-code :value="$u['serial']" class="font-semibold" /> · <x-code :value="$u['sku']" /></span>
                                <span class="text-muted-fg">{{ $u['status_label'] }} · {{ $u['branch'] }}</span>
                            </a></li>
                        @endforeach
                        @foreach ($card['lots'] as $lot)
                            <li class="flex items-center justify-between gap-2 p-2">
                                <span><x-code :value="$lot['sku']" class="font-semibold" /> · {{ $lot['branch'] }}</span>
                                <span class="font-bold"><x-num :value="$lot['quantity']" /></span>
                            </li>
                        @endforeach
                    </ul>

                @else
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-danger-soft text-danger">@svg('heroicon-o-question-mark-circle', 'size-6')</span>
                        <div class="min-w-0">
                            <p class="font-semibold">{{ __('Code not recognised') }}</p>
                            <x-code :value="$card['raw']" class="block break-all text-sm" />
                            <p class="mt-1 text-sm text-muted-fg">{{ __('Saved for the admin, so this label format can be added.') }}</p>
                        </div>
                    </div>
                @endif

                @foreach ($card['warnings'] as $warning)
                    <p class="flex items-start gap-2 text-sm text-warning">
                        @svg('heroicon-o-exclamation-triangle', 'mt-0.5 size-4 shrink-0')
                        <span>{{ $warning }}</span>
                    </p>
                @endforeach

                <div class="flex flex-wrap items-center gap-2 border-t border-border pt-2">
                    @if ($card['gs1'])
                        <x-ops.status-chip :label="__('GS1 code')" color="info" icon="heroicon-o-qr-code" />
                    @endif
                    <x-ops.status-chip :label="match ($card['source']) { 'camera' => __('Camera'), 'wedge' => __('Scanner'), default => __('Typed') }" />
                    <x-date class="text-xs text-muted-fg" :value="$card['at']" time />
                </div>
            </article>
        @empty
            <div class="ot-card">
                <x-ops.empty-state icon="heroicon-o-qr-code" :title="__('Nothing scanned yet')" />
            </div>
        @endforelse
    </section>
</div>

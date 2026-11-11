<div x-data="scanner({ method: 'handleScan' })"
     x-on:quick-answer.window="$nextTick(() => { window.scrollTo({ top: 0, behavior: 'smooth' }); if ($event.detail.focus) $refs.text.focus() })"
     class="space-y-5">
    <header class="space-y-1">
        <h1 class="flex items-center gap-2 text-2xl font-bold">@svg('heroicon-o-chat-bubble-left-right', 'size-7 text-primary') {{ __('Quick answers') }}</h1>
        <p class="text-sm text-muted-fg">{{ __('Tap a question, or type or scan a code.') }}</p>
    </header>

    {{-- Type or scan --}}
    <section class="ot-card space-y-3 p-4" aria-label="{{ __('Ask') }}">
        <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-fg" wire:ignore x-show="cameraOn" x-cloak>
            <video x-ref="video" class="h-full w-full object-cover" playsinline muted aria-label="{{ __('Camera preview') }}"></video>
            <div class="pointer-events-none absolute inset-x-6 top-1/2 h-1/2 -translate-y-1/2 rounded-xl border-4 border-white/80">
                <div class="absolute inset-x-3 top-1/2 h-0.5 -translate-y-1/2 bg-danger"></div>
            </div>
        </div>
        <form wire:submit="send" class="flex gap-2">
            <label for="qa-text" class="sr-only">{{ __('Type or scan a code or a few words') }}</label>
            <input id="qa-text" x-ref="text" wire:model="text" class="ot-input min-w-0 flex-1" autocomplete="off" autocapitalize="off" spellcheck="false"
                   maxlength="200" enterkeyhint="search" placeholder="{{ __('Type or scan a code or a few words') }}">
            <button type="button" class="ot-btn shrink-0 px-3" :class="cameraOn ? 'ot-btn-primary' : 'ot-btn-secondary'" @click="toggleCamera()" :disabled="starting"
                    aria-label="{{ __('Scan with camera') }}" :aria-pressed="cameraOn">
                @svg('heroicon-o-camera', 'size-5')
            </button>
            <button type="submit" class="ot-btn ot-btn-primary shrink-0">{{ __('Ask') }}</button>
        </form>
        <p x-show="cameraError === 'denied'" x-cloak class="text-sm text-danger">{{ __('Camera permission was denied. Allow it in the browser settings, or type the code below.') }}</p>
        <details class="text-sm">
            <summary class="flex min-h-11 cursor-pointer items-center gap-2 font-medium text-muted-fg">
                @svg('heroicon-m-adjustments-horizontal', 'size-5')
                {{ $periods[$period] ?? '' }}@if ($branch) · {{ $branches[(int) $branch] ?? '' }}@endif · {{ $currency }}
            </summary>
            <div class="mt-2 grid grid-cols-2 gap-3">
                <label class="space-y-1">
                    <span class="font-medium">{{ __('Period') }}</span>
                    <select wire:model.live="period" class="ot-input">
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="font-medium">{{ __('Currency') }}</span>
                    <select wire:model.live="currency" class="ot-input" dir="ltr">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($period === 'custom')
                    <label class="space-y-1">
                        <span class="font-medium">{{ __('From') }}</span>
                        <x-ops.date-input wire:model.live="from" />
                    </label>
                    <label class="space-y-1">
                        <span class="font-medium">{{ __('Until') }}</span>
                        <x-ops.date-input wire:model.live="until" />
                    </label>
                @endif
                @if (count($branches) > 1)
                    <label class="col-span-2 space-y-1">
                        <span class="font-medium">{{ __('Branch') }}</span>
                        <select wire:model.live="branch" class="ot-input">
                            <option value="">{{ __('All my branches') }}</option>
                            @foreach ($branches as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        </details>
    </section>

    {{-- Answers, newest first (right under the box) --}}
    <div wire:loading.flex wire:target="askQuestion, send, handleScan" class="items-center gap-2 text-sm text-muted-fg">
        @svg('heroicon-o-arrow-path', 'size-4 animate-spin') {{ __('Looking it up…') }}
    </div>
    @if ($messages !== [])
        <section class="space-y-4" aria-live="polite" aria-label="{{ __('Answers') }}">
            @foreach (array_reverse(array_chunk($messages, 2)) as $pair)
                <div wire:key="{{ $pair[0]['id'] }}" class="space-y-3">
                    @foreach ($pair as $message)
                        @if ($message['from'] === 'me')
                            <div class="flex justify-end">
                                <p class="max-w-[85%] rounded-2xl rounded-ee-sm bg-primary px-4 py-2 font-medium text-on-primary">{{ $message['text'] }}</p>
                            </div>
                        @else
                            @if (filled($message['period'] ?? null))
                                <p class="text-xs font-medium text-muted-fg"><bdi dir="ltr">{{ $message['period'] }}</bdi></p>
                            @endif
                            @if (filled($message['text'] ?? null))
                                <p class="max-w-[85%] rounded-2xl rounded-es-sm bg-subtle px-4 py-2">{{ $message['text'] }}</p>
                            @endif
                            @foreach ($message['cards'] ?? [] as $card)
                                <x-ops.fact-card :fact="$card" />
                            @endforeach
                            @if (! empty($message['suggestions']))
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($message['suggestions'] as $suggestion)
                                        <button type="button" wire:click="askQuestion('{{ $suggestion['key'] }}')" class="ot-btn ot-btn-secondary min-h-11 px-3 text-start text-sm">
                                            {{ $suggestion['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
            @endforeach
            <button type="button" wire:click="clearChat" class="ot-btn ot-btn-secondary w-full">{{ __('Clear the answers') }}</button>
        </section>
    @endif

    {{-- Ready-made questions --}}
    <nav class="space-y-4" aria-label="{{ __('Questions') }}">
        @foreach ($menu as $group => $block)
            <section class="space-y-2" aria-labelledby="qa-group-{{ $group }}">
                <h2 id="qa-group-{{ $group }}" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ $block['label'] }}</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ($block['questions'] as $question)
                        <button type="button" wire:click="askQuestion('{{ $question['key'] }}')" wire:loading.attr="disabled"
                                class="ot-btn ot-btn-secondary min-h-11 px-3 text-start text-sm">
                            {{ $question['label'] }}
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    </nav>
</div>

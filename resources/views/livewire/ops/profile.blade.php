<div class="space-y-6">
    <header class="flex items-center gap-3">
        <span class="flex size-14 items-center justify-center rounded-full bg-primary-soft text-xl font-bold text-primary">
            {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
        </span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold">{{ $user->name }}</h1>
            <x-code :value="$user->username" class="text-sm text-muted-fg" />
        </div>
    </header>

    {{-- Language --}}
    <section class="ot-card space-y-3 p-4" aria-labelledby="lang-title">
        <h2 id="lang-title" class="flex items-center gap-2 font-semibold">
            @svg('heroicon-o-language', 'size-5 text-primary') {{ __('Language') }}
        </h2>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($locales as $code => $locale)
                <button type="button" wire:click="setLocale('{{ $code }}')" lang="{{ $code }}" dir="{{ $locale['dir'] }}"
                        @if ($user->locale === $code) aria-pressed="true" @endif
                        @class([
                            'ot-btn px-2 text-base',
                            'ot-btn-primary' => $user->locale === $code,
                            'ot-btn-secondary' => $user->locale !== $code,
                        ])>
                    {{ $locale['native'] }}
                </button>
            @endforeach
        </div>
    </section>

    {{-- Push notifications --}}
    @if ($vapidPublicKey)
        <section class="ot-card space-y-3 p-4" aria-labelledby="push-title"
                 x-data="{
                    supported: 'serviceWorker' in navigator && 'PushManager' in window,
                    enabled: false,
                    busy: false,
                    async init() {
                        if (!this.supported) return
                        const reg = await navigator.serviceWorker.getRegistration('/')
                        this.enabled = !!(await reg?.pushManager.getSubscription())
                    },
                    async enable() {
                        this.busy = true
                        try {
                            const reg = await navigator.serviceWorker.ready
                            const key = Uint8Array.from(atob(@js($vapidPublicKey).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0))
                            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
                            await fetch(@js(route('ops.push.store')), {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify(sub.toJSON()),
                            })
                            this.enabled = true
                            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: @js(__('Notifications are on for this device.')) } }))
                        } catch (e) {
                            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: @js(__('Notifications could not be turned on. Check the browser permission.')) } }))
                        } finally {
                            this.busy = false
                        }
                    },
                 }">
            <h2 id="push-title" class="flex items-center gap-2 font-semibold">
                @svg('heroicon-o-bell', 'size-5 text-primary') {{ __('Notifications on this device') }}
            </h2>
            <p x-show="!supported" class="text-sm text-muted-fg">{{ __('This browser does not support notifications. On iPhone, install the app to the home screen first.') }}</p>
            <template x-if="supported">
                <div>
                    <p x-show="enabled" class="flex items-center gap-2 text-sm text-success">@svg('heroicon-o-check-circle', 'size-5') {{ __('On') }}</p>
                    <button type="button" x-show="!enabled" class="ot-btn ot-btn-primary w-full" @click="enable()" :disabled="busy">{{ __('Turn on notifications') }}</button>
                </div>
            </template>
        </section>
    @endif

    {{-- Devices --}}
    <section class="ot-card p-4" aria-labelledby="devices-title">
        <h2 id="devices-title" class="flex items-center gap-2 font-semibold">
            @svg('heroicon-o-device-phone-mobile', 'size-5 text-primary') {{ __('My devices') }}
        </h2>
        <p class="mt-1 text-sm text-muted-fg">{{ __('Lost a phone? Log it out here.') }}</p>
        <ul class="mt-3 divide-y divide-border">
            @foreach ($devices as $device)
                <li wire:key="device-{{ $device->id }}" class="flex items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $device->device_label }}</p>
                        <p class="text-xs text-muted-fg">
                            <x-date :value="$device->last_activity_at" time /> · <x-code :value="$device->ip_address" />
                        </p>
                    </div>
                    @if ($device->id === $currentSessionId)
                        <x-ops.status-chip :label="__('This device')" color="success" icon="heroicon-o-check-circle" />
                    @else
                        <button type="button" class="ot-btn ot-btn-secondary text-sm text-danger"
                                wire:click="revokeDevice({{ $device->id }})"
                                wire:confirm="{{ __('Log out this device?') }}">
                            {{ __('Log out') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Logout --}}
    <form method="POST" action="{{ \Filament\Facades\Filament::getPanel('admin')->getLogoutUrl() }}">
        @csrf
        <button type="submit" class="ot-btn ot-btn-secondary w-full text-danger">
            @svg('heroicon-o-arrow-right-start-on-rectangle', 'size-5')
            {{ __('Log out') }}
        </button>
    </form>

    <x-powered-by />
</div>

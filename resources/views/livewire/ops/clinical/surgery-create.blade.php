<div x-data="surgeryForm({
        initial: @js($initial),
        listUrl: @js(route('ops.surgeries.index')),
        strings: { savedOffline: @js(__('Saved on this phone — sent when online.')) },
     })"
     @scanned.window="onScan($event.detail.raw)" class="space-y-5">
    <a href="{{ route('ops.surgeries.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('My surgeries') }}</span>
    </a>

    <h1 class="text-2xl font-bold">{{ __('New surgery') }}</h1>

    @unless ($canHere)
        <div class="ot-card border-warning p-4 text-sm font-semibold">{{ __('Not allowed in this branch.') }}</div>
    @endunless

    <p x-show="!online" x-cloak class="ot-card flex items-center gap-2 border-warning p-3 text-sm font-semibold">
        @svg('heroicon-o-signal-slash', 'size-5 shrink-0 text-warning')
        <span>{{ __('Offline — saved on this phone') }}</span>
    </p>

    <template x-if="problems.length > 0">
        <div class="ot-card border-danger space-y-1 p-4" role="alert">
            <p class="font-semibold text-danger">{{ __('Fix these:') }}</p>
            <ul class="list-disc ps-5 text-sm">
                <template x-for="problem in problems" :key="problem"><li x-text="problem"></li></template>
            </ul>
        </div>
    </template>

    {{-- The surgery --}}
    <section class="ot-card space-y-4 p-4" aria-labelledby="what-title">
        <h2 id="what-title" class="font-semibold">{{ __('Surgery') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="space-y-1">
                <span class="text-sm font-medium">{{ __('Date') }}</span>
                <input type="date" x-model="form.surgery_date" class="ot-input" dir="ltr" required>
                <span class="block text-xs text-muted-fg ot-code" dir="ltr" x-text="form.surgery_date"></span>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">{{ __('Surgery') }}</span>
                <select x-model.number="form.surgery_type_id" class="ot-input">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($types as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">{{ __('Hospital') }}</span>
                <select x-model.number="form.customer_id" class="ot-input">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($hospitals as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset class="space-y-1">
                <legend class="text-sm font-medium">{{ __('Side') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($lateralities as $value => $label)
                        <label class="ot-btn ot-btn-secondary min-h-10 px-3 text-sm" :class="form.laterality === @js($value) && 'ring-2 ring-primary'">
                            <input type="radio" class="sr-only" value="{{ $value }}" x-model="form.laterality">{{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>
    </section>

    {{-- The patient: typed here; the patient file is made when the surgery is completed. --}}
    <section class="ot-card space-y-4 p-4" aria-labelledby="patient-title">
        <h2 id="patient-title" class="font-semibold">{{ __('Patient') }}</h2>

        <p x-show="form.patient_id" x-cloak class="flex items-center justify-between gap-2 rounded-lg bg-subtle p-3">
            <span class="font-semibold" x-text="patientLabel"></span>
            <button type="button" class="text-sm font-semibold text-primary" @click="form.patient_id = null; patientLabel = ''">{{ __('Change') }}</button>
        </p>

        <div x-show="! form.patient_id" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="space-y-1 sm:col-span-2">
                <span class="text-sm font-medium">{{ __('Full name') }}</span>
                <input type="text" x-model="form.new_patient.full_name" class="ot-input" maxlength="150" autocomplete="off">
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">{{ __('Phone') }}</span>
                <input type="tel" x-model="form.new_patient.phone" class="ot-input" dir="ltr" data-western-digits maxlength="32" autocomplete="off">
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium">{{ __('Age') }}</span>
                <input type="number" inputmode="numeric" min="0" max="130" x-model.number="form.new_patient.age" class="ot-input" dir="ltr" data-western-digits>
            </label>
            <label class="space-y-1 sm:col-span-2">
                <span class="text-sm font-medium">{{ __('Gender') }}</span>
                <select x-model="form.new_patient.gender" class="ot-input">
                    <option value="">—</option>
                    @foreach ($genders as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    {{-- Implants and products --}}
    <div x-data="scanner({})">
        <x-ops.scan-box id="surgery-code" :hint="__('Scan each implant')" />
    </div>

    <section class="space-y-3" aria-labelledby="items-title">
        <h2 id="items-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Implants and products used') }}</h2>
        <template x-for="(item, index) in form.items" :key="index">
            <div class="ot-card flex items-center gap-3 p-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold ot-code" dir="ltr" x-text="item.raw || item.label"></p>
                </div>
                <label class="flex items-center gap-1 text-sm">
                    <span class="sr-only">{{ __('Quantity') }}</span>
                    <span aria-hidden="true">×</span>
                    <input type="number" inputmode="numeric" min="1" x-model.number="item.quantity" class="ot-input w-20 text-center" dir="ltr" data-western-digits>
                </label>
                <button type="button" class="ot-btn ot-btn-secondary min-h-11 px-3" @click="removeItem(index)" aria-label="{{ __('Remove') }}">
                    @svg('heroicon-o-x-mark', 'size-5')
                </button>
            </div>
        </template>
        <div x-show="form.items.length === 0" class="ot-card"><x-ops.empty-state icon="heroicon-o-qr-code" :title="__('Nothing scanned yet')" /></div>
    </section>

    {{-- Optional: kit, doctors, notes. Opens by itself when something needs fixing or is filled in. --}}
    <section class="ot-card p-4" x-data="{ more: false }"
             x-init="more = problems.length > 0 || !! form.notes || !! form.override_reason || !! form.kit_checkout_id || form.assistant_ids.length > 0"
             x-effect="if (problems.length > 0) more = true">
        <button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 font-semibold" @click="more = ! more" :aria-expanded="more">
            <span class="flex items-center gap-2">
                @svg('heroicon-o-adjustments-horizontal', 'size-5 text-primary')
                <span x-text="more ? @js(__('Less')) : @js(__('More'))"></span>
            </span>
            @svg('heroicon-o-chevron-down', 'size-5 transition-transform', [':class' => "more && 'rotate-180'"])
        </button>
        <div x-show="more" x-cloak class="mt-4 space-y-5">
            @if ($checkouts !== [])
                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __('Kit') }}</span>
                    <select x-model.number="form.kit_checkout_id" class="ot-input">
                        <option value="">{{ __('No kit') }}</option>
                        @foreach ($checkouts as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Lead surgeon') }}</span>
                <select x-model.number="form.lead_doctor_id" class="ot-input">
                    @foreach ($doctors as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">{{ __('Assistants') }}</legend>
                @foreach ($doctors as $id => $name)
                    <label class="flex min-h-11 items-center gap-3" x-show="form.lead_doctor_id !== {{ $id }}">
                        <input type="checkbox" value="{{ $id }}" x-model.number="form.assistant_ids" class="size-5 rounded">
                        <span>{{ $name }}</span>
                    </label>
                @endforeach
            </fieldset>
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Notes') }}</span>
                <textarea x-model="form.notes" rows="2" class="ot-input"></textarea>
            </label>
            <label class="block space-y-1">
                <span class="text-sm font-medium">{{ __('Reason for expired items') }}</span>
                <input type="text" x-model="form.override_reason" class="ot-input" maxlength="500">
            </label>
        </div>
    </section>

    <p x-show="message" x-cloak role="status" class="ot-card p-4 text-sm font-semibold" x-text="message"></p>

    <div class="sticky bottom-20 z-10 -mx-4 grid grid-cols-2 gap-3 border-t border-border bg-bg px-4 py-3">
        <button type="button" class="ot-btn ot-btn-secondary" :disabled="sending" @click="submit(false)">{{ __('Save draft') }}</button>
        <button type="button" class="ot-btn ot-btn-primary" :disabled="sending || form.items.length === 0" @click="submit(true)">
            <span x-show="!sending">{{ __('Complete surgery') }}</span>
            <span x-show="sending" x-cloak>{{ __('Sending…') }}</span>
        </button>
    </div>
    <button type="button" class="w-full text-center text-sm font-semibold text-danger" @click="discard()">{{ __('Discard draft') }}</button>
</div>

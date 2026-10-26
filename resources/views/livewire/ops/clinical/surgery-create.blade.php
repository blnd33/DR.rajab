<div x-data="surgeryForm({
        initial: @js($initial),
        patientLookup: @js(route('ops.lookup.patients')),
        listUrl: @js(route('ops.surgeries.index')),
        strings: { savedOffline: @js(__('Saved on this phone. It is sent as soon as there is a connection.')) },
     })"
     @scanned.window="onScan($event.detail.raw)" class="space-y-5">
    <a href="{{ route('ops.surgeries.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('My surgeries') }}</span>
    </a>

    <header class="space-y-1">
        <h1 class="text-2xl font-bold">{{ __('New surgery') }}</h1>
        @if ($branch)
            <p class="text-muted-fg">{{ $branch->code }} — {{ $branch->name }}</p>
        @endif
    </header>

    @unless ($canHere)
        <div class="ot-card border-warning p-4 text-sm font-semibold">{{ __('You cannot log surgeries in this branch. Switch branch at the top.') }}</div>
    @endunless

    <div x-show="!online" x-cloak class="ot-card border-warning p-4 text-sm">
        <p class="font-semibold">{{ __('No connection') }}</p>
        <p class="text-muted-fg">{{ __('Keep going: everything is kept on this phone and sent when the connection is back.') }}</p>
    </div>

    <template x-if="problems.length > 0">
        <div class="ot-card border-danger space-y-1 p-4" role="alert">
            <p class="font-semibold text-danger">{{ __('Not completed yet — fix these:') }}</p>
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
            @if ($checkouts !== [])
                <label class="space-y-1 sm:col-span-2">
                    <span class="text-sm font-medium">{{ __('Kit used (optional)') }}</span>
                    <select x-model.number="form.kit_checkout_id" class="ot-input">
                        <option value="">{{ __('No kit') }}</option>
                        @foreach ($checkouts as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
    </section>

    {{-- The patient --}}
    <section class="ot-card space-y-4 p-4" aria-labelledby="patient-title">
        <div class="flex items-center justify-between gap-2">
            <h2 id="patient-title" class="font-semibold">{{ __('Patient') }}</h2>
            <div class="flex gap-1 text-sm" role="tablist">
                <button type="button" role="tab" class="ot-btn min-h-9 px-3" :class="patientMode === 'pick' ? 'ot-btn-primary' : 'ot-btn-secondary'" @click="patientMode = 'pick'">{{ __('Find') }}</button>
                <button type="button" role="tab" class="ot-btn min-h-9 px-3" :class="patientMode === 'new' ? 'ot-btn-primary' : 'ot-btn-secondary'" @click="patientMode = 'new'">{{ __('New patient') }}</button>
            </div>
        </div>

        <div x-show="patientMode === 'pick'" class="space-y-3">
            <p x-show="form.patient_id" class="flex items-center justify-between gap-2 rounded-lg bg-subtle p-3">
                <span class="font-semibold" x-text="patientLabel"></span>
                <button type="button" class="text-sm font-semibold text-primary" @click="form.patient_id = null; patientLabel = ''">{{ __('Change') }}</button>
            </p>
            <div x-show="!form.patient_id" class="space-y-2">
                <label class="block space-y-1">
                    <span class="text-sm font-medium">{{ __('File number, name or phone') }}</span>
                    <input type="search" x-model="patientQuery" @input.debounce.400ms="searchPatients()" class="ot-input" autocomplete="off">
                </label>
                <p x-show="!online" class="text-sm text-muted-fg">{{ __('Search needs a connection — or add the patient as new.') }}</p>
                <ul class="divide-y divide-border">
                    <template x-for="patient in patientResults" :key="patient.id">
                        <li><button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 py-2 text-start" @click="pickPatient(patient)">
                            <span x-text="patient.label"></span>
                            <span class="text-sm text-muted-fg" x-text="patient.age ?? ''"></span>
                        </button></li>
                    </template>
                </ul>
            </div>
        </div>

        <div x-show="patientMode === 'new'" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <label class="space-y-1">
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

    {{-- Doctors --}}
    <section class="ot-card space-y-4 p-4" aria-labelledby="doctors-title">
        <h2 id="doctors-title" class="font-semibold">{{ __('Doctors') }}</h2>
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
    </section>

    {{-- Implants and products --}}
    <div x-data="scanner({})">
        <x-ops.scan-box id="surgery-code" :hint="__('Scan each implant and lot box used. A lot box scanned again counts one more.')" />
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

    <section class="ot-card space-y-4 p-4">
        <label class="block space-y-1">
            <span class="text-sm font-medium">{{ __('Notes') }}</span>
            <textarea x-model="form.notes" rows="2" class="ot-input"></textarea>
        </label>
        <label class="block space-y-1">
            <span class="text-sm font-medium">{{ __('Reason to use expired items (only if there are any)') }}</span>
            <input type="text" x-model="form.override_reason" class="ot-input" maxlength="500">
        </label>
        <p class="text-sm text-muted-fg">{{ __('Photos of the implant stickers can be added once the surgery is saved.') }}</p>
    </section>

    <p x-show="message" x-cloak role="status" class="ot-card p-4 text-sm font-semibold" x-text="message"></p>

    <div class="sticky bottom-20 z-10 -mx-4 grid grid-cols-2 gap-3 border-t border-border bg-bg px-4 py-3">
        <button type="button" class="ot-btn ot-btn-secondary" :disabled="sending" @click="submit(false)">{{ __('Save draft') }}</button>
        <button type="button" class="ot-btn ot-btn-primary" :disabled="sending || form.items.length === 0" @click="submit(true)">
            <span x-show="!sending">{{ __('Complete surgery') }}</span>
            <span x-show="sending" x-cloak>{{ __('Sending…') }}</span>
        </button>
    </div>
    <button type="button" class="w-full text-center text-sm font-semibold text-danger" @click="discard()">{{ __('Discard this draft on the phone') }}</button>
</div>

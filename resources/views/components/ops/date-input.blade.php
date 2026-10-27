{{--
    Native date field (phone keyboards and calendars) with the chosen date echoed as YYYY-MM-DD,
    because the device shows dates in its own format (P5-8). Pass wire:model etc. as attributes.
--}}
<div x-data="{ value: '' }" x-init="$nextTick(() => value = $refs.input.value)" class="space-y-1">
    <input x-ref="input" type="date"
        {{ $attributes->merge(['class' => 'ot-input', 'dir' => 'ltr']) }}
        x-on:input="value = $event.target.value"
        x-on:change="value = $event.target.value">
    <span class="block text-xs text-muted-fg ot-code" dir="ltr" x-show="value" x-text="value" x-cloak></span>
</div>

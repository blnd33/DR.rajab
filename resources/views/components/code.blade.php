{{-- Serial / lot / SKU / barcode / phone / document number: Western digits, LTR, monospace. --}}
@props(['value'])
<bdi {{ $attributes->class(['ot-code']) }} dir="ltr">{{ $value }}</bdi>

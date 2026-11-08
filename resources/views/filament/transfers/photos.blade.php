@php
    use App\Enums\AttachmentKind;
    use App\Support\Locale\Display;

    /** @var \App\Models\Transfer $transfer */
    $transfer = $getRecord();
    $slots = [
        [$transfer->photo(AttachmentKind::TransferSent), __('Our photo (when sent)'), __('Taken by :branch before sending.', ['branch' => $transfer->fromBranch?->code])],
        [$transfer->photo(AttachmentKind::TransferReceived), __('Their photo (when received)'), __('Taken by :branch when the items arrived.', ['branch' => $transfer->toBranch?->code])],
    ];
@endphp

<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    @foreach ($slots as [$photo, $title, $hint])
        <figure class="space-y-2">
            <figcaption>
                <span class="block text-sm font-semibold">{{ $title }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
            </figcaption>
            @if ($photo !== null)
                <a href="{{ route('attachments.show', $photo) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10">
                    <img src="{{ route('attachments.show', $photo) }}" alt="{{ $title }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                </a>
                <span class="block text-xs text-gray-500 dark:text-gray-400">
                    {{ $photo->uploader?->name }} · <span dir="ltr">{{ Display::dateTime($photo->created_at) }}</span>
                </span>
            @else
                <div class="flex aspect-[4/3] w-full flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
                    @svg('heroicon-o-camera', 'size-8')
                    <span>{{ __('No photo yet') }}</span>
                </div>
            @endif
        </figure>
    @endforeach
</div>

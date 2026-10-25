<x-filament-widgets::widget>
    <x-filament::section :heading="__('Expiry by branch')" icon="heroicon-o-clock"
                         :description="__('Items on hand per expiry level (serial units + lot quantities).')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500">
                        <th class="px-2 py-2 text-start font-medium">{{ __('Level') }}</th>
                        @foreach ($branches as $branch)
                            <th class="px-2 py-2 text-end font-medium">{{ $branch->code }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-end font-semibold">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($counts as $row)
                        @php($level = $row['level'])
                        <tr>
                            <td class="px-2 py-2">
                                <span class="inline-flex items-center gap-2 whitespace-nowrap">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ring-black/10 dark:ring-white/30" style="background-color: {{ $level->color }}; color: {{ $level->text_color }};">{{ $level->name }}</span>
                                    <span class="text-xs text-gray-500" dir="ltr">{{ $level->rangeLabel() }}</span>
                                </span>
                            </td>
                            @foreach ($branches as $branch)
                                @php($qty = $row['by_branch'][$branch->id] ?? 0)
                                <td @class(['px-2 py-2 text-end tabular-nums', 'text-gray-400' => $qty === 0, 'font-semibold' => $qty > 0])>
                                    <x-num :value="$qty" />
                                </td>
                            @endforeach
                            <td class="px-2 py-2 text-end font-bold tabular-nums"><x-num :value="$row['total']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

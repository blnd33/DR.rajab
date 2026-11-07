<?php

namespace App\Filament\Admin\Resources\DataImports\RelationManagers;

use App\Enums\ImportRowAction;
use App\Enums\UsageLevel;
use App\Models\DataImportRow;
use App\Support\FieldLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Preview of every row: what will happen, old → new per field, errors and warnings.
 */
class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Rows');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('row_number')
            ->columns([
                TextColumn::make('row_number')->label(__('Row'))->sortable()->alignEnd()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('action')->label(__('Result'))->badge(),
                TextColumn::make('key')
                    ->label(__('Item'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['dir' => 'ltr'])
                    ->description(fn (DataImportRow $record) => isset($record->raw['kind']) ? $this->sourceLine($record) : null)
                    ->state(fn (DataImportRow $record) => implode(' · ', array_filter([
                        $record->raw['sku'] ?? $record->raw['code'] ?? null,
                        $record->raw['serial'] ?? null,
                        $record->raw['lot'] ?? null,
                        $record->raw['branch'] ?? null,
                    ], fn ($v) => filled($v)))),
                TextColumn::make('changes')
                    ->label(__('Changes (old → new)'))
                    ->wrap()
                    ->state(fn (DataImportRow $record) => $this->changes($record)),
                TextColumn::make('messages')
                    ->label(__('Errors / warnings'))
                    ->wrap()
                    ->state(fn (DataImportRow $record) => $this->messages($record)),
            ])
            ->filters([
                SelectFilter::make('action')->label(__('Result'))->options(ImportRowAction::options()),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading(__('No rows yet'))
            ->emptyStateDescription(__('Rows appear here once the file has been checked.'));
    }

    private function changes(DataImportRow $row): ?HtmlString
    {
        if ($row->action === ImportRowAction::Create) {
            // Supplier catalog: the usage read from the doctor's colours, to check at a glance.
            $usage = UsageLevel::tryFrom((string) ($row->raw['usage'] ?? ''));
            $usageBadge = $usage === null ? '' : ' · <span class="text-gray-600 dark:text-gray-300">'.e($usage->getLabel()).'</span>';

            return new HtmlString('<span class="text-success-700 dark:text-success-400">'.e(__('New')).'</span>'.$usageBadge);
        }

        if (empty($row->diff)) {
            return null;
        }

        $parts = [];

        foreach ($row->diff as $field => $change) {
            $parts[] = '<div><span class="font-semibold">'.e(FieldLabels::for($field)).':</span> '
                .'<bdi dir="auto" class="text-gray-500 line-through">'.e($this->text($change['old'] ?? null)).'</bdi> → '
                .'<bdi dir="auto" class="font-medium">'.e($this->text($change['new'] ?? null)).'</bdi></div>';
        }

        return new HtmlString(implode('', $parts));
    }

    /**
     * Supplier catalog rows: what the item is and where it is in the workbook.
     */
    private function sourceLine(DataImportRow $row): string
    {
        $kind = match ($row->raw['kind'] ?? null) {
            'implant' => __('Implant'),
            'instrument' => __('Instrument'),
            'set' => __('Instrument set'),
            default => '',
        };

        return trim($kind.' · '.mb_strimwidth((string) ($row->raw['name'] ?? ''), 0, 70, '…').' · '.__(':sheet, row :row', ['sheet' => trim((string) ($row->raw['sheet'] ?? '')), 'row' => $row->raw['row'] ?? '']), ' ·');
    }

    private function messages(DataImportRow $row): ?HtmlString
    {
        $parts = [];

        foreach ($row->errors ?? [] as $error) {
            $parts[] = '<div class="flex gap-1 text-danger-700 dark:text-danger-400">'.svg('heroicon-m-x-circle', 'mt-0.5 size-4 shrink-0')->toHtml().'<span>'.e($error).'</span></div>';
        }

        foreach ($row->warnings ?? [] as $warning) {
            $parts[] = '<div class="flex gap-1 text-warning-700 dark:text-warning-400">'.svg('heroicon-m-exclamation-triangle', 'mt-0.5 size-4 shrink-0')->toHtml().'<span>'.e($warning).'</span></div>';
        }

        return $parts === [] ? null : new HtmlString(implode('', $parts));
    }

    private function text(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }
}
